<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Store\Model\ScopeInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;

/**
 *
 */
class CheckoutAllSubmitAfterObserver implements ObserverInterface
{

    const LUNAR_CREDITCARD_METHODS = [
        ConfigProvider::LUNAR_PAYMENT_CODE,
        // ConfigProvider::LUNAR_PAYMENT_HOSTED_CODE
    ];

    private $logger;
    protected $scopeConfig;
    protected $invoiceCollectionFactory;
    protected $invoiceService;
    protected $invoiceSender;
    protected $transactionFactory;

    /**
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param CollectionFactory $invoiceCollectionFactory
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceSender $invoiceSender
     */
    public function __construct(
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        CollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
    }

    /**
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** Check for "order" - normal checkout flow. */
        $order = $observer->getEvent()->getOrder();
        /** Check for "orders" - multishipping checkout flow. */
        $orders = $observer->getEvent()->getOrders();

        if (!empty($order)) {
            $this->processOrder($order);
        } elseif (!empty($orders)) {
            foreach ($orders as $order) {
                $this->processOrder($order);
            }
        }

        return $this;
    }

    /**
     * @param Order $order
     */
    private function processOrder(Order $order)
    {
        $payment = $order->getPayment();
        $methodName = $payment->getMethod();

        if (!in_array($methodName, self::LUNAR_CREDITCARD_METHODS)) {
            return $this;
        }

        $captureMode =  $this->scopeConfig->getValue('payment/' . $methodName . '/capture_mode', ScopeInterface::SCOPE_STORE);
        $invoiceEmailMode =  $this->scopeConfig->getValue('payment/' . $methodName . '/invoice_email', ScopeInterface::SCOPE_STORE);

        if ("instant" == $captureMode) {
            if (!$order->getId()) {
                return $this;
            }

            try {
                $invoices = $this->invoiceCollectionFactory->create()
                    ->addAttributeToFilter('order_id', ['eq' => $order->getId()]);
                $invoices->getSelect()->limit(1);

                if ((int)$invoices->count() !== 0) {
                    return null;
                }

                if (!$order->canInvoice()) {
                    return null;
                }

                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->getOrder()->setCustomerNoteNotify(false);
                $invoice->getOrder()->setIsInProcess(true);
                $transactionSave = $this->transactionFactory->create();
                $transactionSave = $transactionSave->addObject($invoice)->addObject($invoice->getOrder());
                $transactionSave->save();

                if (!$invoice->getEmailSent() && $invoiceEmailMode == 1) {
                    try {
                        $this->invoiceSender->send($invoice);
                    } catch (\Exception $e) {
                        // Do something if failed to send
                    }
                }
            } catch (\Exception $e) {
                $order->addStatusHistoryComment('Exception message: ' . $e->getMessage(), false);
                $order->save(); // save() is deprecated !
                return null;
            }
        } else if ("delayed" == $captureMode) {

            $order->setState(Order::STATE_PROCESSING)
                ->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
            $order->save();
        }
    }
}
