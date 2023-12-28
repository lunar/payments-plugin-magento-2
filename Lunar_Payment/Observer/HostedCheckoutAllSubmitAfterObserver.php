<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;

use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 *
 */
class HostedCheckoutAllSubmitAfterObserver implements ObserverInterface
{
    private $orderRepository;

    public function __construct(
        \Magento\Sales\Model\OrderRepository $orderRepository
    ) {
        $this->orderRepository = $orderRepository;
    }

    /**
     *
     * @param  Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** Check for "orders" - multishipping checkout flow. */
        $orders = $observer->getEvent()->getOrders();

        if (!empty($orders)) {

            $firstOrder = $orders[0]; 

            if (! in_array($firstOrder->getPayment()->getMethod(), ConfigProvider::LUNAR_HOSTED_METHODS)) {
                return;
            }

            /** @var \Magento\Sales\Api\Data\OrderInterface $order */
            foreach ($orders as $order) {
                $order->setState(Order::STATE_NEW)->setStatus('pending');
                $this->orderRepository->save($order);
            }
        }

        return $this;

    }
}
