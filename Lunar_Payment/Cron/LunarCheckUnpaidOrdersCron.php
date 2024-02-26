<?php

namespace Lunar\Payment\Cron;

use Lunar\Exception\ApiException;
use Psr\Log\LoggerInterface;

use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;
use Lunar\Lunar;

/**
 * Cron responsible for checking unpaid orders to see if they are authorized 
 * even if the customer did't return to the website to finalize transaction 
 */
class LunarCheckUnpaidOrdersCron
{
    private $storeManager;
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $cookieManager;

    private $invoiceCollectionFactory;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;
    private $orderCollectionFactory;
    private string $transactionId = '';
    private $intentIdKey = '_lunar_intent_id';
    private $quotePayment = null;

    // private ?Order $order = null;
    /** @var Order|Quote $order */
    private $order = null;

    private array $args = [];
    private string $paymentMethodCode = '';


    public function __construct(
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        PriceCurrencyInterface $priceCurrencyInterface,
        CookieManagerInterface $cookieManager,
        OrderCollectionFactory $orderCollectionFactory
    ) {

        $this->storeManager              = $storeManager;
        $this->logger                    = $logger;
        $this->scopeConfig               = $scopeConfig;
        $this->orderRepository           = $orderRepository;
        $this->invoiceCollectionFactory  = $invoiceCollectionFactory;
        $this->invoiceService            = $invoiceService;
        $this->transactionFactory        = $transactionFactory;
        $this->invoiceSender             = $invoiceSender;
        $this->priceCurrencyInterface    = $priceCurrencyInterface;
        $this->cookieManager             = $cookieManager;
        $this->orderCollectionFactory    = $orderCollectionFactory;
    }


    public function execute()
    {        
        $lunarOrders = $this->orderCollectionFactory->create()
            ->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'state',
                // ['in' => [Order::STATE_NEW]]
                ['in' => ['no_status']]
            )->setOrder(
                'created_at',
                'desc'
        );
 
        foreach ($lunarOrders as $this->order) {

            $this->paymentMethodCode = $this->order->getPayment()->getMethod();

            $privateKey =  $this->getStoreConfigValue('app_key');
            $testMode = !!$this->cookieManager->getCookie('lunar_testmode');
            
            $lunarApiClient = new Lunar($privateKey, null, $testMode);

            $transactionId = $this->getPaymentIntentFromOrder();

            if (empty($transactionId)) {
                $this->logger->debug('Lunar polling - no transaction id on order: ' . $this->order->getId());
                continue;
            }

            try {
                $transaction = $lunarApiClient->payments()->fetch($transactionId);
            } catch (ApiException $e) {
                $this->logger->debug(
                    'Exception during fetch in Lunar polling: orderid - '
                    . $this->order->getId() . ' -- '
                    . $e->getMessage()
                );
            }

            $result = $this->parseApiTransactionResponse($transaction);

            if (empty($transaction)) {
                $this->logger->debug(
                    'Lunar polling - no transaction: orderid - '
                    . $this->order->getId()
                    . ' -- trnsaction id: '. $transactionId
                );
            }

            $this->transactionId = $transaction['id'];


            $this->finalizeOrder();
        }

    }


    private function finalizeOrder()
    {
        /** Update info on order payment */
        $this->setTxnIdOnQuotePayment();
        $this->setTxnIdOnOrderPayment();

        if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
            // the order state will be changed after invoice creation
            $this->createInvoiceForOrder();
        } else {
            /**
             * @see https://magento.stackexchange.com/questions/225524/magento-2-show-pending-payment-order-in-store-front/280227#280227
             * Important note for Pending Payments
             * If you have a "pending payment" status order,
             * Magento 2 will cancel the order automatically after 8 hours if the payment status doesn't change.
             * To change that, go to Stores > Configuration > Sales > Order Cron Settings
             * and change the Lifetime to a greater value.
             *
             * If pending_payment orders not show in front, @see https://magento.stackexchange.com/a/225531/100054
             */
            $this->insertNewTransactionForOrderPayment();
            $this->order->setState(Order::STATE_PROCESSING)->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
        }

        $this->orderRepository->save($this->order);
    }

    /**
     *
     */
    private function setTxnIdOnQuotePayment()
    {
        try {
            $additionalInformation = ['transactionid' => $this->transactionId] + $this->quotePayment->getAdditionalInformation();
            $this->quotePayment->setAdditionalInformation($additionalInformation);
            $this->quotePayment->save();
        } catch (\Exception $e) {
            $this->logger->debug(
                'Exception during save transaction ID on quote payment in Lunar polling: orderid - '
                . $this->order->getId() . ' -- '
                . $e->getMessage()
            );
        }
    }

    /**
     *
     */
    private function setTxnIdOnOrderPayment()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            $baseGrandTotal = $this->order->getBaseGrandTotal();
            $grandTotal = $this->order->getGrandTotal();

            $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
            $orderPayment->setAmountAuthorized($grandTotal);

            $additionalInformation = ['transactionid' => $this->transactionId] + $orderPayment->getAdditionalInformation();
            $orderPayment->setAdditionalInformation($additionalInformation);
            $orderPayment->setLastTransId($this->transactionId);
            $orderPayment->setQuotePaymentId($this->quotePayment->getId());
            $orderPayment->save();
        } catch (\Exception $e) {
            $this->logger->debug(
                'Exception during save transaction ID on order payment in Lunar polling: orderid - '
                . $this->order->getId() . ' -- '
                . $e->getMessage()
            );
        }
    }

    /**
     *
     */
    private function insertNewTransactionForOrderPayment()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            $orderPayment->setTransactionId($this->transactionId);
            $orderPayment->setIsTransactionClosed(0);
            $orderPayment->setShouldCloseParentTransaction(0);
            $transaction = $orderPayment->addTransaction(TransactionInterface::TYPE_AUTH, null, $failSafe = true);

            $commentContent = 'Authorized amount of ' . $this->getFormattedPriceWithCurrency() . '.';
            $orderPayment->addTransactionCommentsToOrder($transaction, $commentContent);
        } catch (\Exception $e) {
            $this->logger->debug(
                'Exception during insert transaction in Lunar polling: orderid - '
                . $this->order->getId() . ' -- '
                . $e->getMessage()
            );
        }
    }

    /**
     *
     */
    private function getPaymentIntentFromOrder()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists($this->intentIdKey, $additionalInformation)) {
            return $this->paymentIntentId = $additionalInformation[$this->intentIdKey];
        }

        return false;
    }

    /**
     *
     */
    private function createInvoiceForOrder()
    {
        $invoiceEmailMode =  $this->getStoreConfigValue('invoice_email');

        try {
            $invoices = $this->invoiceCollectionFactory->create()
                ->addAttributeToFilter('order_id', array('eq' => $this->order->getId()));
            $invoices->getSelect()->limit(1);

            if ((int)$invoices->count() !== 0) {
                return null;
            }

            if (!$this->order->canInvoice()) {
                return null;
            }

            $invoice = $this->invoiceService->prepareInvoice($this->order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);
            $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();

            if (!$invoice->getEmailSent() && $invoiceEmailMode == 1) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                    // Do something if failed to send
                }
            }
        } catch (\Exception $e) {
            $this->order->addCommentToStatusHistory('Exception message: ' . $e->getMessage(), false);
            $this->orderRepository->save($this->order);
            return null;
        }
    }

    /**
     *
     */
    private function getStoreConfigValue($configKey)
    {
        return $this->scopeConfig->getValue(
            'payment/' . $this->paymentMethodCode . '/' . $configKey,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }

    /**
     * Parses api transaction response for errors
     */
    protected function parseApiTransactionResponse($transaction)
    {
        if (!$this->isTransactionSuccessful($transaction)) {
            $this->logger->debug("Transaction with error: " . json_encode($transaction, JSON_PRETTY_PRINT));
            return false;
        }

        return true;
    }

    /**
     * Checks if the transaction was successful and
     * the data was not tempered with.
     *
     * @return bool
     */
    private function isTransactionSuccessful($transaction)
    {
        $matchCurrency = $this->args['amount']['currency'] == $transaction['amount']['currency'];
        $matchAmount = $this->args['amount']['decimal'] == $transaction['amount']['decimal'];

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     *
     * @param  array $result The result returned by the api wrapper.
     * @return string
     */
    private function getResponseError($result)
    {
        $error = [];
        // if this is just one error
        if (isset($result['text'])) {
            return $result['text'];
        }

        if (isset($result['code']) && isset($result['error'])) {
            return $result['code'] . '-' . $result['error'];
        }

        // otherwise this is a multi field error
        if ($result) {
            foreach ($result as $fieldError) {
                if (isset($fieldError['field'])) {
                    $error[] = $fieldError['field'] . ':' . $fieldError['message'];
                } elseif (isset($fieldError['error'])) {
                    $error[] = $fieldError['code'] . ':' . $fieldError['error'];
                } else {
                    $error[] = 'Lunar generic error. Please try again';
                }
            }
        }

        return implode(' ', $error);
    }

    /**
     *
     */
    private function getFormattedPriceWithCurrency($useOrderCurrency = false)
    {
        $orderTotal = $this->order->getBaseGrandTotal();
        $currency = $this->order->getBaseCurrencyCode();

        if ($useOrderCurrency) {
            $orderTotal = $this->order->getGrandTotal();
            $currency = $this->order->getOrderCurrencyCode();
        }

        return $this->priceCurrencyInterface->format(
            $orderTotal,
            $includeContainer = false,
            $precision = 2,
            $scope = null,
            $currency
        );
    }
}
