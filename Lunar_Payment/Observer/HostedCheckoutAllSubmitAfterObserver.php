<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;

use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 * Class HostedCheckoutAllSubmitAfterObserver
 */
class HostedCheckoutAllSubmitAfterObserver implements ObserverInterface
{
    /**
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** Check for "orders" - multishipping checkout flow. */
        $orders = $observer->getEvent()->getOrders();

        if (!empty($orders)) {
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

        if ( ! in_array($methodName, ConfigProvider::LUNAR_HOSTED_METHODS)) {
            return;
        }

        $order->setState(Order::STATE_NEW)->setStatus('pending');
        $order->save();
    }
}
