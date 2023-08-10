<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
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

    const LUNAR_HOSTED_METHODS = [ 
        ConfigProvider::LUNAR_PAYMENT_HOSTED_CODE,
        ConfigProvider::MOBILEPAY_HOSTED_CODE,
    ];

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

        if ( ! in_array($methodName, self::LUNAR_HOSTED_METHODS)) {
            return $this;
        }

        $order->setState(Order::STATE_NEW)->setStatus('pending');
        $order->save();
        return $this;
    }
}
