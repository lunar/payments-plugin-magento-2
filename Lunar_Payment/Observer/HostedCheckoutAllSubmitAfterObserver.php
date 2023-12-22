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
    private $storeManager;
    private $responseFactory;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ResponseFactory $responseFactory
    ) {
        $this->storeManager = $storeManager;
        $this->responseFactory = $responseFactory;
    }

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

            $firstOrder = $orders[0]; 

            if ( ! in_array($firstOrder->getPayment()->getMethod(), ConfigProvider::LUNAR_HOSTED_METHODS)) {
                return;
            }


            foreach ($orders as $order) {
                $order->setState(Order::STATE_NEW)->setStatus('pending');
                $order->save();
            }
        }

        $redirectUrl = $this->storeManager->getStore()->getBaseUrl()
                        . '/lunar/index/HostedCheckout/?multishipping=1&quote_id=' . $firstOrder->getQuoteId();
        $this->responseFactory->create()->setRedirect($redirectUrl)->sendResponse();
        die();
    }
}
