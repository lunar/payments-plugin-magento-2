<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderPaymentInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 * Change order status to canceled when void
 */
class SalesOrderPaymentVoidObserver implements ObserverInterface
{
    const LUNAR_PAYMENT_METHODS = [
        ConfigProvider::LUNAR_PAYMENT_CODE,
        ConfigProvider::MOBILEPAY_CODE,
        
        ConfigProvider::LUNAR_PAYMENT_HOSTED_CODE,
        ConfigProvider::MOBILEPAY_HOSTED_CODE,
    ];

    /**
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** @var OrderPaymentInterface $payment */
        $payment = $observer->getEvent()->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();

        if (!empty($order)) {
            $methodName = $payment->getMethod();

            if ( ! in_array($methodName, self::LUNAR_PAYMENT_METHODS)) {
                return $this;
            }

            if (!$order->getId()) {
                return $this;
            }

            $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
            $order->save();
        }

        return $this;
    }
}