<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
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


class CheckoutAllSubmitAfterObserver implements ObserverInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     *
     * @var CollectionFactory
     */
    protected $invoiceCollectionFactory;

    /**
     *
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     *
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     *
     * @var TransactionFactory
     */
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
        $order = $observer->getEvent()->getOrder();
        if(!isset($order)){
         return $this;
     }
     $payment = $order->getPayment();
     $methodName = $payment->getMethod();

     if ($methodName != "lunarpaymentmethod"){
        return $this;
    }

    $capturemode =  $this->scopeConfig->getValue('payment/lunarpaymentmethod/capture_mode', ScopeInterface::SCOPE_STORE);

    if($capturemode == "instant"){
        if(!$order->getId()) {
            return $this;
        }


        try {
            $invoices = $this->invoiceCollectionFactory->create()
            ->addAttributeToFilter('order_id', array('eq' => $order->getId()));
            $invoices->getSelect()->limit(1);

            if ((int)$invoices->count() !== 0) {
                return null;
            }

            if(!$order->canInvoice()) {
                return null;
            }

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);
            $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();

            $invoiceEmailmode =  $this->scopeConfig->getValue('payment/lunarpaymentmethod/invoice_email', ScopeInterface::SCOPE_STORE);
            if (!$invoice->getEmailSent() && $invoiceEmailmode==1) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                        // Do something if failed to send
                }
            }
        } catch (\Exception $e) {
            $order->addStatusHistoryComment('Exception message: '.$e->getMessage(), false);
            $order->save();
            return null;
        }
    }

    else if($capturemode == "delayed"){

        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
        $order->save();
    }

    return $this;
}
}
