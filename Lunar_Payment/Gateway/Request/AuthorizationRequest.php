<?php

namespace Lunar\Payment\Gateway\Request;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Lunar\Payment\Observer\DataAssignObserver;

class AuthorizationRequest implements BuilderInterface
{
    private $request;
    private $checkoutSession;
    private $orderRepository;
    private $cartRepository;

    public function __construct(
        RequestInterface $request,
        Session $checkoutSession,
        OrderRepository $orderRepository,
        CartRepositoryInterface $cartRepository
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->cartRepository = $cartRepository;
        
    }

    /**
     * Builds ENV request
     *
     * @param  array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (
            !isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException(__('Payment data object should be provided'));
        }

        /** @var PaymentDataObjectInterface $payment */
        $paymentDO = $buildSubject['payment'];

        $orderAdapter = $paymentDO->getOrder();
        $address = $orderAdapter->getBillingAddress();

        /** 
         * The following line can be removed after hosted checkout migration is completed. 
         * Also adjust quote extraction and other thigs bellow
         */
        /** @var \Magento\Quote\Api\Data\CartInterface $quote */
        $quote = $this->checkoutSession->getQuote();

        if ($orderId = $this->request->getParam('order_id')) {
            $order = $this->orderRepository->get($orderId);
            /** @var \Magento\Quote\Api\Data\CartInterface $quote */
            $quote = $this->cartRepository->get($order->getQuoteId());
        }
        
        $payment = $paymentDO->getPayment();
        $transactionResult = $payment->getAdditionalInformation(DataAssignObserver::TRANSACTION_RESULT);

        return [
            'TXN_TYPE' => 'authorize',
            'TXN_ID' => $transactionResult,
            'ORDER_ID' => $orderAdapter->getOrderIncrementId(),
            'AMOUNT' => $quote->getGrandTotal(),
            'CURRENCY' => $quote->getQuoteCurrencyCode(),
            'EMAIL' => $address->getEmail()
        ];
    }
}
