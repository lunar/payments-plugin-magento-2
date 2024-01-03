<?php

namespace Lunar\Payment\Gateway\Request;

use Magento\Checkout\Model\Cart;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Lunar\Payment\Observer\DataAssignObserver;

class AuthorizationRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Cart
     */

    protected $cart;

    /**
     * @param ConfigInterface $config
     * @param Cart            $cart
     */
    public function __construct(
        ConfigInterface $config,
        Cart $cart
    ) {
        $this->config = $config;
        $this->cart = $cart;
    }

    /**
     * Builds ENV request
     *
     * @param  array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $payment */
        $payment = $buildSubject['payment'];
        $order = $payment->getOrder();
        $address = $order->getBillingAddress();
        $quote = $this->cart->getQuote();
        $payments = $payment->getPayment();
        $transactionResult = $payments->getAdditionalInformation(DataAssignObserver::TRANSACTION_RESULT);

        return [
            'TXN_TYPE' => 'authorize',
            'TXN_ID' => $transactionResult,
            'ORDER_ID' => $order->getOrderIncrementId(),
            'AMOUNT' => $quote->getGrandTotal(),
            'CURRENCY' => $quote->getQuoteCurrencyCode(),
            'EMAIL' => $address->getEmail()
        ];
    }
}
