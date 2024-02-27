<?php

namespace Lunar\Payment\Cron;

use Psr\Log\LoggerInterface;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

use Lunar\Lunar;
use Lunar\Exception\ApiException;
use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;

/**
 * Cron responsible for checking unpaid orders to see if they are authorized 
 * even if the customer did't return to the website to finalize the transaction 
 */
class LunarCheckUnpaidOrdersCron
{
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;
    private $orderCollectionFactory;
    private $invoiceCollectionFactory;
    
    /** @var Order $order */
    private $order = null;
    private $orderPayment = null;
    private $transactionId = '';
    private $paymentMethodCode = '';


    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        PriceCurrencyInterface $priceCurrencyInterface,
        OrderCollectionFactory $orderCollectionFactory,
        InvoiceCollectionFactory $invoiceCollectionFactory
    ) {
        $this->logger                    = $logger;
        $this->scopeConfig               = $scopeConfig;
        $this->orderRepository           = $orderRepository;
        $this->invoiceService            = $invoiceService;
        $this->transactionFactory        = $transactionFactory;
        $this->invoiceSender             = $invoiceSender;
        $this->priceCurrencyInterface    = $priceCurrencyInterface;
        $this->orderCollectionFactory    = $orderCollectionFactory;
        $this->invoiceCollectionFactory  = $invoiceCollectionFactory;
    }


    public function execute()
    {
        $this->logger->debug('"Start Lunar polling:" - ' . date('Y-m-d H:i:s'));

        $latestOrders = $this->orderCollectionFactory->create()
            ->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_NEW]]
            )->addFieldToFilter(
                'status',
                ['eq' => 'pending']
            );

        $latestOrders
            ->getSelect()
            ->joinLeft(
                ['payment' => 'sales_order_payment'],
                'payment.parent_id = main_table.entity_id',
                ['payment_method' => 'payment.method']
            )->where(
                'payment.method IN (?)', ConfigProvider::LUNAR_HOSTED_METHODS
            );


        foreach ($latestOrders as $this->order) {
file_put_contents(dirname(__FILE__) . "/zzz.log", json_encode('....................', JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);    
file_put_contents(dirname(__FILE__) . "/zzz.log", json_encode($this->order->getId(), JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);    

            $this->paymentMethodCode = $this->order->getPayment()->getMethod();

            $lunarApiClient = new Lunar($this->getStoreConfigValue('app_key'));
            
            /**
             * Uncomment the following line for testing purposes (we don't have cookies access)
             */
            $lunarApiClient = new Lunar($this->getStoreConfigValue('app_key'), null, true);

            $transactionId = $this->getPaymentIntentFromOrder();

            if (empty($transactionId)) {
                $this->logger->debug('Lunar polling: no transaction id on order: ' . $this->order->getId());
                /** We don't want to fetch this order next time */
                $this->order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                $this->orderRepository->save($this->order);
                continue;
            }

            try {
                $transaction = $lunarApiClient->payments()->fetch($transactionId);
            } catch (ApiException $e) {
                $this->logger->debug(
                    'Lunar polling (API Exception): order - ' . $this->order->getId() . ' -- ' . $e->getMessage()
                );
            }

            if (empty($transaction)) {
                $this->logger->debug(
                    'Lunar polling:  no transaction for order - '
                    . $this->order->getId() . ' -- trnsaction id: '. $transactionId
                );
                continue;
            }

            if ($transaction['authorisationCreated'] === true) {
                $this->transactionId = $transaction['id'];
                $this->finalizeOrder();
            } else {
                $this->logger->debug(
                    'Lunar polling: no authorisation for order - '
                    . $this->order->getId() . ' -- transaction id: '. $transactionId
                );
            }
        }

    }

    /**
     *
     */
    private function getPaymentIntentFromOrder()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $this->orderPayment = $this->order->getPayment();
        $additionalInformation = $this->orderPayment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists('transactionid', $additionalInformation)) {
            return $additionalInformation['transactionid'];
        }

        return false;
    }

    /**
     * 
     */
    private function finalizeOrder()
    {
        $this->updateOrderPayment();

        if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
            // the order state will be changed after invoice creation
            $result = $this->createInvoiceForOrder();
        } else {
            $result = $this->insertNewTransactionForOrderPayment();

            if (!empty($result)) {
                $this->order->setState(Order::STATE_PROCESSING)->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
            }
        }

        if (!empty($result)) {
            $this->orderRepository->save($this->order);
            $this->logger->debug('Lunar polling: success for order - ' . $this->order->getId());
        }
    }

    /**
     *
     */
    private function updateOrderPayment()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();
            $quotePaymentId = $this->order->getQuote()?->getPayment()?->getId();

            $baseGrandTotal = $this->order->getBaseGrandTotal();
            $grandTotal = $this->order->getGrandTotal();

            $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
            $orderPayment->setAmountAuthorized($grandTotal);
            $orderPayment->setLastTransId($this->transactionId);
            $orderPayment->setQuotePaymentId($quotePaymentId);
            $orderPayment->save();
        } catch (\Exception $e) {
            $this->logger->debug('Lunar polling: order - ' . $this->order->getId() . ' -- '. $e->getMessage());
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

            return true;
        } catch (\Exception $e) {
            $this->logger->debug(
                'Lunar polling (Exception): cannot insert transaction for order - '
                . $this->order->getId() . ' -- ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     *
     */
    private function createInvoiceForOrder()
    {
        $invoiceEmailMode =  $this->getStoreConfigValue('invoice_email');

        try {
            $invoices = $this->invoiceCollectionFactory->create()
                ->addAttributeToFilter('order_id', ['eq' => $this->order->getId()]);
            $invoices->getSelect()->limit(1);

            if ((int)$invoices->count() !== 0) {
                return null;
            }

            if (!$this->order->canInvoice()) {
                return null;
            }

            /**
             * We'll capture using the api sdk directly
             * We bypass gateway because we cannot set all the data it needs
             */
            // @TODO capture payment here using the api

            $invoice = $this->invoiceService->prepareInvoice($this->order);

            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
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

            return true;

        } catch (\Exception $e) {
            $this->order->addCommentToStatusHistory('Lunar polling (Exception): ' . $e->getMessage(), false);
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
            $this->order->getStoreId()
        );
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
