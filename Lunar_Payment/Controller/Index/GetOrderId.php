<?php

namespace Lunar\Payment\Controller\Index;


use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Controller\Result\JsonFactory;


/**
 *
 */
class GetOrderId implements ActionInterface
{
    private $jsonFactory;
    private $requestInterface;
    private $cartRepositoryInterface;

    public function __construct(
        JsonFactory $jsonFactory,
        RequestInterface $requestInterface,
        CartRepositoryInterface $cartRepositoryInterface
    ) {
		$this->jsonFactory 			= $jsonFactory;
		$this->requestInterface 	= $requestInterface;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
    }

    /**
     *
     */
    public function execute()
    {
        $quoteId = $this->requestInterface->getParams()['quote_id'] ?? '';

		if (!$quoteId) {
            return $this->jsonFactory->create()->setData(['error' => 'No quote ID provided.']);
        }

        $quote = $this->cartRepositoryInterface->get($quoteId);

        $objectManager = ObjectManager::getInstance();
		$orderObject = $objectManager->get('Magento\Sales\Model\Order');

        $order = $orderObject->loadByIncrementId($quote->getReservedOrderId());

        return $this->jsonFactory->create()->setData(['order_id' => $order->getId()]);
    }
}