<?php

namespace Lunar\Payment\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

class TxnIdHandler implements HandlerInterface
{
    private const TXN_ID = 'TXN_ID';

    protected $_invoiceService;

    protected $order;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        Logger $logger,
        InvoiceService $invoiceService,
        Order $order
    ) {
        $this->logger = $logger;
        $this->_invoiceService = $invoiceService;
        $this->order = $order;
    }

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (
            !isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        if (isset($response['TXN_TYPE'])) {

            $this->logger->debug(["txnidhandler: " => $response['TXN_TYPE']]);

            if ($response['TXN_TYPE'] == "void" || $response['TXN_TYPE'] == "refund") {
                $transactionid = $response[self::TXN_ID] . "-" . $response['TXN_TYPE'];
                $payment->setTransactionId($transactionid);
                $payment->setIsTransactionClosed(true);
                $payment->setShouldCloseParentTransaction(true);
            } else {
                $payment->setTransactionId($response[self::TXN_ID]);
                $payment->setIsTransactionClosed(false);
            }
        } else {
            $this->logger->debug(["txnidhandler: " => "not set"]);
            $payment->setTransactionId($response[self::TXN_ID]);
            $payment->setIsTransactionClosed(false);
        }
    }
}
