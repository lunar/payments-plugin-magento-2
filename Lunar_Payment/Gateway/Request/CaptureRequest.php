<?php

namespace Lunar\Payment\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

class CaptureRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Order
     */
    private $order;

    /**
     * @param ConfigInterface $config
     * @param Order $order
     */
    public function __construct(
        ConfigInterface $config,
        Order $order
    ) {
        $this->config = $config;
        $this->order = $order;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (
            !isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $orderId = $paymentDO->getOrder()->getOrderIncrementId();
        $order = $this->order->loadByIncrementId($orderId);
        $payment = $paymentDO->getPayment();

        $transactionId = $payment->getLastTransId();
        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException(__('Order payment should be provided.'));
        }

        if (!$transactionId) {
            throw new LocalizedException(__('No authorization transaction to proceed capture.'));
        }

        return [
            'TXN_TYPE' => 'capture',
            'TXN_ID' => $transactionId,
            'AMOUNT' => $payment->getAmountAuthorized(),
            'CURRENCY' => $order->getOrderCurrencyCode(),
        ];
    }
}
