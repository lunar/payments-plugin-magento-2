<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 *
 */
class MobilePayCheckoutAllSubmitAfterObserver implements ObserverInterface
{
    const LUNAR_MOBILEPAY_METHODS = [
        ConfigProvider::MOBILEPAY_CODE,
        // ConfigProvider::MOBILEPAY_HOSTED_CODE,
    ];

    private $methodCode = '';

    private $logger;
    protected $redirectFactory;
    protected $urlInterface;
    protected $scopeConfig;
    protected $invoiceCollectionFactory;
    protected $invoiceService;
    protected $invoiceSender;
    protected $transactionFactory;
    protected $orderStatusHistory;

    /**
     *
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        OrderStatusHistoryInterface $orderStatusHistory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
        $this->orderStatusHistory = $orderStatusHistory;
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
        // $orders = $observer->getEvent()->getOrders();

        if (!$order || !$order->getId()) {
            return $this;
        }

        $payment = $order->getPayment();
        $this->methodCode = $payment ? $payment->getMethod() : '';

        if (!$payment || ! in_array($this->methodCode, self::LUNAR_MOBILEPAY_METHODS)) {
            return $this;
        }

        $checkoutMode =  $this->scopeConfig->getValue('payment/' . $this->methodCode . '/checkout_mode', ScopeInterface::SCOPE_STORE);

        /** Perform redirect in after_order flow. */
        if ('after_order' == $checkoutMode) {
            // redirect from here not working now
        }

        if ('before_order' == $checkoutMode) {
            // // disabled for the moment in multishipping
            // if (!empty($orders)) {
            //     foreach ($orders as $order) {
            //         $this->processOrder($order);
            //     }
            // }
            // else {

            $this->processOrder($order);

            // }
        }

        return $this;
    }

    /**
     * @param Order $order
     */
    private function processOrder(Order $order)
    {
        $captureMode =  $this->scopeConfig->getValue('payment/' . $this->methodCode . '/capture_mode', ScopeInterface::SCOPE_STORE);

        if ("instant" == $captureMode) {

            $this->createInvoiceForOrder($order);
        }
        elseif ("delayed" == $captureMode) {

            $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PENDING_PAYMENT);
            $order->save();
        }
    }

    /**
     *
     */
    private function createInvoiceForOrder($order)
    {
        $invoiceEmailMode =  $this->scopeConfig->getValue('payment/' . $this->methodCode . '/invoice_email', ScopeInterface::SCOPE_STORE);

        try {
            $invoices = $this->invoiceCollectionFactory->create()
                ->addAttributeToFilter('order_id', array('eq' => $order->getId()));
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
            $order->addStatusHistoryComment('Exception message: ' . $e->getMessage(), false); // addStatusHistoryComment() is deprecated !
            $order->save(); // save() is deprecated !
            return null;
        }
    }
}
