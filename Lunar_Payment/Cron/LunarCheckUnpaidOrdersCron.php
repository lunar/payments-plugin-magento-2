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
use Magento\Sales\Api\OrderPaymentRepositoryInterface as OrderPaymentRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection as TransactionCollectionFactory;

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
    private const ACTION_CAPTURE = 'capture';
 
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;
    private $orderCollectionFactory;
    private $invoiceCollectionFactory;
    private $orderPaymentRepository;
    
    /** @var Order $order */
    private $order = null;
    private $transactionId = '';
    private $paymentMethodCode = '';
    private $apiClient;


    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        PriceCurrencyInterface $priceCurrencyInterface,
        OrderCollectionFactory $orderCollectionFactory,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        OrderPaymentRepository $orderPaymentRepository,
    ) {
        $this->logger                        = $logger;
        $this->scopeConfig                   = $scopeConfig;
        $this->orderRepository               = $orderRepository;
        $this->invoiceService                = $invoiceService;
        $this->transactionFactory            = $transactionFactory;
        $this->invoiceSender                 = $invoiceSender;
        $this->priceCurrencyInterface        = $priceCurrencyInterface;
        $this->orderCollectionFactory        = $orderCollectionFactory;
        $this->invoiceCollectionFactory      = $invoiceCollectionFactory;
        $this->orderPaymentRepository        = $orderPaymentRepository;
    }


    public function execute()
    {
        $this->logger->debug('"Start Lunar polling:" - ' . date('Y-m-d H:i:s'));

        $timestamp = time();
        $to = date('Y-m-d H:i:s', $timestamp);
        $aDayAgo = $timestamp - 86400;
        $from = date('Y-m-d H:i:s', $aDayAgo);

        $latestOrders = $this->orderCollectionFactory->create()
            ->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_NEW]]
            )->addFieldToFilter(
                'status',
                ['eq' => 'pending']
            )->addFieldToFilter(
                'created_at',
                [
                    'from' => $from,
                    'to' => $to,
                    'datetime' => true,
                ]
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


        /** @TODO delegate order processing logic */

        foreach ($latestOrders as $this->order) {

            $this->paymentMethodCode = $this->order->getPayment()->getMethod();

            $this->apiClient = new Lunar($this->getStoreConfigValue('app_key'));
            
            /**
             * Uncomment the following line for testing purposes (we don't have cookies access)
             */
            $this->apiClient = new Lunar($this->getStoreConfigValue('app_key'), null, true);

            $this->transactionId = $this->getPaymentIntentFromOrder();

            if (empty($this->transactionId)) {
                $this->logger->debug('Lunar polling: no transaction id on order: ' . $this->order->getId());
                /** We don't want to fetch this order next time */
                $this->order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                $this->orderRepository->save($this->order);
                continue;
            }

            try {
                $apiResponse = $this->apiClient->payments()->fetch($this->transactionId);
            } catch (ApiException $e) {
                $this->logger->debug(
                    'Lunar polling (API Exception): order - ' . $this->order->getId() . ' -- ' . $e->getMessage()
                );
            }

            if (empty($apiResponse)) {
                $this->logger->debug(
                    'Lunar polling:  no transaction for order - '
                    . $this->order->getId() . ' -- trnsaction id: '. $this->transactionId
                );
                continue;
            }

            if ($apiResponse['authorisationCreated'] === true) {
                $this->finalizeOrder();
            } else {
                $this->logger->debug(
                    'Lunar polling: no authorisation for order - '
                    . $this->order->getId() . ' -- transaction id: '. $this->transactionId
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
        $orderPayment = $this->order->getPayment();
        $additionalInformation = $orderPayment->getAdditionalInformation();

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

        $this->order->setState(Order::STATE_PROCESSING)
                    ->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
        $this->orderRepository->save($this->order);


        if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
            /**
             * We'll capture using the api sdk directly
             * We bypass gateway because we cannot set all the data it needs
             */
            try {
                $apiResponse = $this->apiClient->payments()->capture($this->transactionId);

                if (isset($apiResponse['captureState']) && 'completed' == $apiResponse['captureState']) {
                    $this->updateOrderPayment(self::ACTION_CAPTURE);

                    $result = $this->upsertPaymentTransaction(self::ACTION_CAPTURE);
                    
                    // the order state will be changed after invoice creation
                    $result = $this->createInvoiceForOrder();
                }

            } catch (ApiException $e) {
                $this->logger->debug(
                    'Lunar polling (API CAPTURE Exception): order - ' 
                    . $this->order->getId() . ' -- ' . $e->getMessage()
                );
            }

        } else {
            $result = $this->upsertPaymentTransaction();
        }

        if (!empty($result)) {
            $this->logger->debug('Lunar polling: success for order - ' . $this->order->getId());
        }
    }

    /**
     *
     */
    private function updateOrderPayment($actionType = 'authorize')
    {
        try {
            /** @var \Magento\Sales\Api\Data\OrderPaymentInterface $orderPayment */
            $orderPayment = $this->order->getPayment();
            
            $baseGrandTotal = $this->order->getBaseGrandTotal();
            $grandTotal = $this->order->getGrandTotal();
            
            if (self::ACTION_CAPTURE == $actionType) {
                $orderPayment->setBaseAmountPaid($baseGrandTotal);
                $orderPayment->setBaseAmountPaidOnline($baseGrandTotal);
                $orderPayment->setAmountPaid($grandTotal);
            } else {
                $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
                $orderPayment->setAmountAuthorized($grandTotal);
                $orderPayment->setLastTransId($this->transactionId);

                $quotePaymentId = $this->order->getQuote()?->getPayment()?->getId();
                $orderPayment->setQuotePaymentId($quotePaymentId);
            }

            $this->orderPaymentRepository->save($orderPayment);
        } catch (\Exception $e) {
            $this->logger->debug('Lunar polling: order - ' . $this->order->getId() . ' -- '. $e->getMessage());
        }
    }

    /**
     *
     */
    private function upsertPaymentTransaction($actionType = 'authorize')
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            $commentContent = 'Authorized amount of ' . $this->getFormattedPriceWithCurrency() . '.';
            $transactionType = TransactionInterface::TYPE_AUTH;

            if (self::ACTION_CAPTURE == $actionType) {
                $orderPayment->setTransactionId('................................');
                $orderPayment->setIsTransactionClosed(1);
                $orderPayment->setParentTransactionId($this->transactionId);
                $orderPayment->setShouldCloseParentTransaction(1);
                $commentContent = 'Captured amount of ' . $this->getFormattedPriceWithCurrency() . '.';
                $transactionType = TransactionInterface::TYPE_CAPTURE;
            } else {
                $orderPayment->setIsTransactionClosed(0);
                $orderPayment->setShouldCloseParentTransaction(0);
            }
            

            $orderPayment->save();

            $transaction = $orderPayment->addTransaction($transactionType, null, $failSafe = true);
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
