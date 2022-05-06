<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Gateway\Request;

use Magento\Framework\App\Request\Http;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;

class RefundRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Order
     */
    private $order;

    protected $request;

    /**
     * @param ConfigInterface $config
     * @param Order $order
     */
    public function __construct(
        ConfigInterface $config,
        Order $order,
        Http $request
    ) {
        $this->config = $config;
        $this->order = $order;
        $this->request = $request;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $orderId = $paymentDO->getOrder()->getOrderIncrementId();
        $order = $this->order->loadByIncrementId($orderId);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException(__('Order payment should be provided.'));
        }

        $baseTotalInvoiced = $order->getBaseTotalInvoiced();
        $shipping = $order->getBaseShippingAmount();
        $baseSubtotal = $baseTotalInvoiced - $shipping;
        $adjustments = $this->request->getParam('creditmemo');
        $shippingAmount = $adjustments['shipping_amount'];
        $adjustmentPositive = $adjustments['adjustment_positive'];
        $adjustmentNegative = $adjustments['adjustment_negative'];
        $baseAmount = $baseSubtotal + $shippingAmount + $adjustmentPositive - $adjustmentNegative;
        $rate = $order->getBaseToOrderRate();
        $amount = $baseAmount * $rate;

        return [
            'TXN_TYPE' => 'refund',
            'TXN_ID' => $payment->getParentTransactionId(),
            'AMOUNT' => $amount,
            'CURRENCY' => $order->getOrderCurrencyCode()
        ];
    }
}
