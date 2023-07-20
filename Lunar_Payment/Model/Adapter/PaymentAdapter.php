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
    private $scopeConfig;
    private $request;
    private $orderRepository;
    private $storeManager;
    private $order = null;
    private $paymentMethodCode = '';
    
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

        if ($orderId = $this->request->getParam('order_id')) {
            $this->order = $this->orderRepository->get($orderId);
        }

        $this->setPaymentMethodCode();

        $this->setPrivateKey();
    }


    /**
     * @param string|null $value
     */
    private function setPrivateKey()
    {
        $transactionMode = $this->getStoreConfigValue('transaction_mode');

        $privateKey = '';

        $privateKey = $this->getStoreConfigValue('live_app_key');
        if($transactionMode == "test"){
            $privateKey = $this->getStoreConfigValue('test_app_key');
        }

        Client::setKey($privateKey, $this->paymentMethodCode);
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
        $configPath = 'payment/' . $this->paymentMethodCode . '/' . $configField;

        return $this->scopeConfig->getValue(
            /*path*/ $configPath,
            /*scopeType*/ ScopeInterface::SCOPE_STORE,
            /*scopeCode*/ $this->getStoreId()
        );
    }

    /**
     * 
     */
    private function getStoreId($order = null)
    {
        if ($this->order) {
            return $order->getStore()->getId();
        }
        
        /** FRONTEND order processing flow. */
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Get payment method code from either quote or order
     * @return string
     */
    private function setPaymentMethodCode()
    {
        /**
         * ADMIN order processing flow (if order_id is present, the request is from admin)
         * OR
         * MOBILEPAY flow
         */
        if ($this->order) {
            $this->paymentMethodCode = $order->getPayment()->getMethod();
        }

        /**
         * Get payment method code from cart if request came from frontend.
         * It is null in the after_order flow
         */
        $this->paymentMethodCode = $this->getPaymentMethodFromQuote();
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
