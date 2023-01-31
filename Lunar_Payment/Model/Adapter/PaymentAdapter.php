<?php

namespace Lunar\Payment\Model\Adapter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;

use Lunar\Payment\lib\Lunar\Client;
use Lunar\Payment\lib\Lunar\Transaction;

/**
 * Class PaymentAdapter
 * @codeCoverageIgnore
 * Adapter used for capture/refund/void an order
 */
class PaymentAdapter
{
    public function __construct(
            ScopeConfigInterface $scopeConfig,
            RequestInterface $request,
            OrderRepository $orderRepository,
            StoreManagerInterface $storeManager
    ){

        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->storeManager = $storeManager;


        $this->setPrivateKey();
    }


    /**
     * @param string|null $value
     */
    private function setPrivateKey()
    {
        $transactionMode = $this->getStoreConfigValue('transaction_mode');

        $privateKey = '';

        if($transactionMode == "test"){
            $privateKey = $this->getStoreConfigValue('test_app_key');
        }

        else if($transactionMode == "live"){
            $privateKey = $this->getStoreConfigValue('live_app_key');
        }

        Client::setKey($privateKey);
    }

    /**
     * @param string $transactionId
     * @param array $data
     * @return array
     */
    public function capture($transactionId, array $data)
    {
        return Transaction::capture($transactionId, $data);
    }

    /**
     * @param string $transactionId
     * @param array $data
     * @return array
     */
    public function void($transactionId, array $data)
    {
        return Transaction::void($transactionId, $data);
    }

    /**
     * @param string $transactionId
     * @param array $data
     * @return array
     */
    public function refund($transactionId, array $data)
    {
        return Transaction::refund($transactionId, $data);
    }

    /**
     * Get store config value
     *
     * @param string $configField
     */
    private function getStoreConfigValue($configField)
    {
        /**
         * Get payment method code from cart if request came from frontend.
         * It is null iat this point n the after_order flow, but will be obtained bellow from order.
         */
        $paymentMethodCode = $this->getPaymentMethodFromQuote();

        /** FRONTEND order processing flow. */
        $orderStoreId = $this->storeManager->getStore()->getId();

        /**
         * ADMIN order processing flow (if order_id is present, the request is from admin)
         * OR
         * MOBILEPAY flow
         */
        if ($orderId = $this->request->getParam('order_id')) {

            $order = $this->orderRepository->get($orderId);

            $orderStoreId = $order->getStore()->getId();

            $paymentMethod = $order->getPayment()->getMethod();
            $paymentMethodCode = $paymentMethod;
        }

        /**
         * "path" is composed based on etc/adminhtml/system.xml as "section_id/group_id/field_id"
         */
        $configPath = 'payment/' . $paymentMethodCode . '/' . $configField;

        return $this->scopeConfig->getValue(
            /*path*/ $configPath,
            /*scopeType*/ ScopeInterface::SCOPE_STORE,
            /*scopeCode*/ $orderStoreId
        );
    }


	/**
	 *
	 */
	private function getPaymentMethodFromQuote()
	{
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$cart = $objectManager->get('\Magento\Checkout\Model\Cart');

		return $cart->getQuote()->getPayment()->getMethod();
	}

}
