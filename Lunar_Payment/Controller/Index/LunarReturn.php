<?php

namespace Lunar\Payment\Controller\Index;

use Psr\Log\LoggerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Response\Http;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

use Lunar\Lunar;
use Lunar\Exception\ApiException;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;

/**
 * Controller responsible to manage return request fron hosted checkout page
 */
class LunarReturn implements \Magento\Framework\App\ActionInterface
{
    private $storeManager;
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $jsonFactory;
    private $request;
    private $redirectFactory;
    private $response;
    private $messageManager;
    private $cartRepositoryInterface;
    private $cookieManager;
    private $orderCollectionFactory;

    /** @var Order|null $order */
    private $order = null;
    /** @var Quote|null $quote */
    private $quote = null;
    private Lunar $apiClient;
    private string $baseURL = '';
    private ?string $quoteId = '';
    private string $paymentIntentId = '';
    private string $paymentMethodCode = '';
    private bool $testMode = false;
    private bool $isMultishipping = false;
    private $multishippingQuotePayment = null;


    public function __construct(
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        JsonFactory $jsonFactory,
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        Http $response,
        ManagerInterface $messageManager,
        CartRepositoryInterface $cartRepositoryInterface,
        CookieManagerInterface $cookieManager,
        OrderCollectionFactory $orderCollectionFactory
    ) {
        $this->storeManager             = $storeManager;
        $this->logger                   = $logger;
        $this->scopeConfig              = $scopeConfig;
        $this->orderRepository          = $orderRepository;
        $this->jsonFactory              = $jsonFactory;
        $this->request                  = $request;
        $this->redirectFactory          = $redirectFactory;
        $this->response                 = $response;
        $this->messageManager           = $messageManager;
        $this->cartRepositoryInterface  = $cartRepositoryInterface;
        $this->cookieManager            = $cookieManager;
        $this->orderCollectionFactory   = $orderCollectionFactory;

        $this->initialize();
    }

    /**
     *
     */
    public function initialize()
    {
        $orderId = $this->request->getParam('order_id');
        $this->quoteId = $this->request->getParam('multishipping_quote_id');
        
        if (empty($orderId) && empty($this->quoteId)) {
            return;
        }

        if (!empty($orderId)) {
            $this->order = $this->orderRepository->get($orderId);
            $this->paymentMethodCode = $this->order->getPayment()->getMethod();
        } 
        
        if (!empty($this->quoteId)) {
            $this->isMultishipping = true;

            /** @var \Magento\Quote\Model\Quote $quote */
            $this->quote = $this->cartRepositoryInterface->get($this->quoteId);
            $this->multishippingQuotePayment = $this->quote->getPayment();
            $this->paymentMethodCode = $this->multishippingQuotePayment->getMethod();
        }

        $this->baseURL = $this->storeManager->getStore()->getBaseUrl();

        $this->testMode = !!$this->cookieManager->getCookie('lunar_testmode');

        $this->apiClient = new Lunar($this->getStoreConfigValue('app_key'), null, $this->testMode);
    }

    /**
     *
     */
    public function execute()
    {        
        if (!$this->order && !$this->quote) {
            return $this->sendJsonResponse(['error' => 'Please provide order or quote id']);
        }

        $transaction = null;
    
        try {
            $transaction = $this->apiClient->payments()->fetch($this->getPaymentIntent());
        } catch (ApiException $e) {
            return $this->redirectToErrorPage('API exception: '.$e->getMessage());
        }

        if (!$this->parseApiTransactionResponse($transaction)) {
            return $this->redirectToErrorPage($this->getResponseError($transaction));
        }

        $dataRedirectUrl = $this->baseURL;

        if ($this->isMultishipping) {
            $orders = $this->getOrderCollectionByQuoteId($this->quoteId);

            foreach ($orders as $this->order) {
                $this->finalizeOrder();
            }

            $dataRedirectUrl .= 'multishipping/checkout/success';

        } else {
            $this->finalizeOrder();
            $dataRedirectUrl .= 'checkout/onepage/success';
        }

        return $this->response->setRedirect($dataRedirectUrl);
    }

    /**
     * 
     */
    private function finalizeOrder()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            try {
                /** 
                 * We don't need to re-authorize in case someone access 
                 * the return link again, or move back in the browser
                 */
                if (!$orderPayment->getAmountAuthorized()) {
                    $orderPayment->setTransactionId($this->paymentIntentId);
                    $orderPayment->setAmountAuthorized($this->order->getGrandTotal());
    
                    if ($this->isMultishipping) {
                        $orderPayment->setQuotePaymentId($this->multishippingQuotePayment->getId());
                    } else {
                        $orderPayment->setQuotePaymentId($this->order->getQuote()?->getPayment()?->getId());
                    }

                    $orderPayment->authorize($isOnline = true, $this->order->getBaseGrandTotal());
                }

            } catch (ApiException $e) {
                return $this->redirectToErrorPage($e->getMessage());
            }

            if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
                
                try {
                    $orderPayment->capture();
                } catch (ApiException $e) {
                    $this->logger->debug('Lunar capture failed. Reason: '.$e->getMessage());
                    $this->order->setState(Order::STATE_PROCESSING)
                                ->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
                }

            } else {
                $this->order->setState(Order::STATE_PROCESSING)
                            ->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
            }
            
            $this->orderRepository->save($this->order);

        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
        }
    }

    /**
     *
     */
    private function getOrderCollectionByQuoteId($quoteId)
    {
        return $this->orderCollectionFactory->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter(
                'quote_id',
                ['eq' => $quoteId]
            );
    }

    /**
     *
     */
    private function getPaymentIntent()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if ($this->multishippingQuotePayment) {
            $payment = $this->multishippingQuotePayment;
        } else {
            $payment = $this->order->getPayment();
        }

        $additionalInformation = $payment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists('transactionid', $additionalInformation)) {
            return $this->paymentIntentId = $additionalInformation['transactionid'];
        }

        return false;
    }

    /**
     *
     */
    private function getStoreConfigValue($configKey)
    {
        return $this->scopeConfig->getValue(
            'payment/' . $this->paymentMethodCode . '/' . $configKey,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }

    /**
     *
     */
    private function sendJsonResponse($response, $code = 200)
    {
        if ($this->isMultishipping) {
            return $this->redirectToErrorPage($response);
        }

        return $this->jsonFactory->create()->setHttpResponseCode($code)->setData($response);
    }

    /**
     * Set session error and redirect to custom page
     */
    private function redirectToErrorPage($errorMessage)
    {
        // $this->messageManager->addError($errorMessage); // deprecated, but it can render html tags if needed
        $this->messageManager->addErrorMessage($errorMessage);
        $dataRedirectUrl = 'lunar/index/displayerror';
        $resultRedirect = $this->redirectFactory->create();
        return $resultRedirect->setPath($dataRedirectUrl);
    }

    /**
     * Parses api transaction response for errors
     */
    protected function parseApiTransactionResponse($transaction)
    {
        if (!$this->isTransactionSuccessful($transaction)) {
            $this->logger->debug("Transaction with error: " . json_encode($transaction, JSON_PRETTY_PRINT));
            return false;
        }

        return true;
    }

    /**
     * Checks if the transaction was successful and
     * the data was not tempered with.
     *
     * @return bool
     */
    private function isTransactionSuccessful($transaction)
    {        
        if ($this->quote) {
            $currency = $this->quote->getQuoteCurrencyCode();
            $grandTotal = $this->quote->getGrandTotal();
        } else {
            $currency = $this->order->getOrderCurrencyCode();
            $grandTotal = $this->order->getGrandTotal();
        }

        $matchCurrency = $currency == $transaction['amount']['currency'] ?? null;
        $matchAmount = $grandTotal == $transaction['amount']['decimal'] ?? null;

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     *
     * @param  array $result The result returned by the api wrapper.
     * @return string
     */
    private function getResponseError($result)
    {
        $error = [];
        // if this is just one error
        if (isset($result['text'])) {
            return $result['text'];
        }

        if (isset($result['code']) && isset($result['error'])) {
            return $result['code'] . '-' . $result['error'];
        }

        // otherwise this is a multi field error
        if ($result) {
            foreach ($result as $fieldError) {
                if (isset($fieldError['field'])) {
                    $error[] = $fieldError['field'] . ':' . $fieldError['message'];
                } elseif (isset($fieldError['error'])) {
                    $error[] = $fieldError['code'] . ':' . $fieldError['error'];
                } else {
                    $error[] = 'Lunar generic error. Please try again';
                }
            }
        }

        return implode(' ', $error);
    }
}
