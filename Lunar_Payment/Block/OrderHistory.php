<?php

namespace Lunar\Payment\Block;

use \Magento\Framework\App\ObjectManager;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactoryInterface;
use Magento\Framework\Webapi\Rest\Request;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Sales order history block extension
 */
class OrderHistory extends \Magento\Sales\Block\Order\History
{
    private $curl;
    private $baseURL;

    /**
     * @var CollectionFactoryInterface
     */
    private $orderCollectionFactory;

    /**
     * {@inheritdoc}
     */
    public function getOrders()
    {
        if (!($customerId = $this->_customerSession->getCustomerId())) {
            return false;
        }

        if (!$this->orders) {

            // $startDate = date("Y-m-d h:i:s", strtotime('-1 day')); // start date
            // $startDate = date("Y-m-d h:i:s", strtotime('-60 minutes')); // start date
            $startDate = date("Y-m-d h:i:s", strtotime('-20 minutes')); // start date
            $endDate = date('Y-m-d h:i:s'); // end date

            $ordersToCheck = $this->orders = $this->getOrderCollectionFactory()->create($customerId)->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'status',
                ['eq' => 'pending']
            )->addAttributeToFilter(
                'created_at',
                [
                    'from' => $startDate,
                    'to' => $endDate
                ]
            );

            $this->curl = ObjectManager::getInstance()->get(\Magento\Framework\HTTP\Client\Curl::class);
            $urlInterface = ObjectManager::getInstance()->get(\Magento\Backend\Model\UrlInterface::class);
            $this->baseURL = \Magento\Framework\App\Request\Http::getUrlNoScript($urlInterface->getBaseUrl());

            foreach ($ordersToCheck as $order) {
                $result = $this->makeCurlRequest('api/V1/lunar_payment/checkOrder', ['order_id' => $order->getId()]);
                // $result = $this->curl->post($this->baseURL . 'api/V1/lunar_payment/checkOrder', ['order_id' => $order->getId()]);
            }

            // default code
            $this->orders = $this->getOrderCollectionFactory()->create($customerId)->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'status',
                ['in' => $this->_orderConfig->getVisibleOnFrontStatuses()]
            )->setOrder(
                'created_at',
                'desc'
            );
        }

        return $this->orders;
    }

    /**
     * Provide order collection factory
     *
     * @return     CollectionFactoryInterface
     * @deprecated 100.1.1
     */
    private function getOrderCollectionFactory()
    {
        if ($this->orderCollectionFactory === null) {
            $this->orderCollectionFactory = ObjectManager::getInstance()->get(CollectionFactoryInterface::class);
        }
        return $this->orderCollectionFactory;
    }

    /**
     *
     */
    private function makeCurlRequest(
        string $uriEndpoint,
        array $params = [],
        string $requestMethod = Request::HTTP_METHOD_POST
    ) {

        $allParams = [
            'headers' => [
                'Content-Type' => "application/json",
            ],
            'version' => '1.0',
            'body' => json_encode($params)
        ];

        try {
            $guzzleClient = new GuzzleClient(
                [
                'base_uri' => $this->baseURL,
                ]
            );

            $response = $guzzleClient->request($requestMethod, $uriEndpoint, $allParams);
            $response = json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $exception) {
            $response = ['error' => $exception->getMessage()];
        }

        return $response;
    }
}
