<?php

namespace Lunar\Payment\Model\Adapter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Model\Cart;

use Lunar\Payment\lib\Lunar\Client;
use Lunar\Payment\lib\Lunar\Transaction;

/**
 * Class PaymentAdapter
 *
 * @codeCoverageIgnore
 * Adapter used for capture/refund/void an order
 */
class PaymentAdapter
{
    private $scopeConfig;
    private $request;
    private $orderRepository;
    private $storeManager;
    private $cartRepository;
    private $cart;
    private $order = null;
    private $paymentMethodCode = '';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        OrderRepository $orderRepository,
        StoreManagerInterface $storeManager,
        CartRepositoryInterface $cartRepository,
        Cart $cart
    ) {

        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->storeManager = $storeManager;
        $this->cartRepository = $cartRepository;
        $this->cart = $cart;

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
        /**
         * @TODO get only app_key after complete hosted checkout migration
         */
        if ($this->getStoreConfigValue('app_key')) {
            $privateKey = $this->getStoreConfigValue('app_key');
        } else {
            $privateKey = "test" == $this->getStoreConfigValue('transaction_mode')
                ? $this->getStoreConfigValue('test_app_key')
                : $this->getStoreConfigValue('live_app_key');
        }

        Client::setKey($privateKey, $this->paymentMethodCode);
    }

    /**
     * @param  string $transactionId
     * @param  array  $data
     * @return array
     */
    public function capture($transactionId, array $data)
    {
        return Transaction::capture($transactionId, $data);
    }

    /**
     * @param  string $transactionId
     * @param  array  $data
     * @return array
     */
    public function void($transactionId, array $data)
    {
        return Transaction::void($transactionId, $data);
    }

    /**
     * @param  string $transactionId
     * @param  array  $data
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
            /*path*/
            $configPath,
            /*scopeType*/
            ScopeInterface::SCOPE_STORE,
            /*scopeCode*/
            $this->getStoreId()
        );
    }

    /**
     *
     */
    private function getStoreId($order = null)
    {
        if ($this->order) {
            return $this->order->getStore()->getId();
        }

        /** FRONTEND order processing flow. */
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Get payment method code from either quote or order
     *
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
            return $this->paymentMethodCode = $this->order->getPayment()->getMethod();
        }

        /**
         * Get payment method code from cart if request came from frontend.
         * It is null in the after_order flow
         */
        return $this->paymentMethodCode = $this->getPaymentMethodFromQuote();
    }

    /**
     *
     */
    private function getPaymentMethodFromQuote()
    {
        if ($quoteId = $this->request->getParam('multishipping_quote_id')) {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $this->cartRepository->get($quoteId);

            return $quote->getPayment()->getMethod();
        }

        return $this->cart->getQuote()->getPayment()->getMethod();
    }
}
