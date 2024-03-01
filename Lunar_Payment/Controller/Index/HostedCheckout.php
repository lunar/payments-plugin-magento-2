<?php

namespace Lunar\Payment\Controller\Index;

use Lunar\Exception\ApiException;
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
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;
use Lunar\Lunar;

/**
 * Controller responsible to manage Hosted Checkout payments
 *
 * NOTE: for multishipping flow, we set the quote in $this->order property
 *
 * @TODO change the logic to use interchangeable order/quote objects 
 */
class HostedCheckout implements \Magento\Framework\App\ActionInterface
{
    private const REMOTE_URL = 'https://pay.lunar.money/?id=';
    private const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';
    
    private $configProvider;
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
    private $priceCurrencyInterface;
    private $cookieManager;
    private $orderCollectionFactory;
    private $orderModel;

    private Lunar $apiClient;
    private string $transactionId = '';
    private string $baseURL = '';
    private string $quoteId = '';

    /** @var Order|Quote $order */
    private $order = null;

    private array $args = [];
    private string $paymentIntentId = '';
    private string $controllerURL = 'lunar/index/HostedCheckout';
    private string $paymentMethodCode = '';
    private bool $testMode = false;
    private string $publicKey = '';
    private bool $isMultishipping = false;


    public function __construct(
        ConfigProvider $configProvider,
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
        PriceCurrencyInterface $priceCurrencyInterface,
        CookieManagerInterface $cookieManager,
        OrderCollectionFactory $orderCollectionFactory,
        Order $orderModel
    ) {

        $this->configProvider           = $configProvider;
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
        $this->priceCurrencyInterface   = $priceCurrencyInterface;
        $this->cookieManager            = $cookieManager;
        $this->orderCollectionFactory   = $orderCollectionFactory;
        $this->orderModel               = $orderModel;

        $response = $this->initialize();

        if ($response) {
            return $this->sendJsonResponse(['error' => true]);
        }
    }

    /**
     *
     */
    public function initialize()
    {
        /**
         * If request has order_id or multishipping_quote_id, the request is coming from hosted page
         */
        if ($this->request->getParam('order_id')) {
            $orderId = $this->request->getParam('order_id');
        }

        if ($this->request->getParam('multishipping_quote_id')) {
            $this->isMultishipping = true;
            $this->quoteId = $this->request->getParam('multishipping_quote_id');
        } 

        if ('1' == $this->request->getParam('multishipping')) {
            $this->isMultishipping = true;
            $this->quoteId = $this->request->getParam('quote_id');
        }

        if ($this->request->getParam('quote_id')) {
            $this->quoteId = $this->request->getParam('quote_id');
        }

        if (empty($orderId) && empty($this->quoteId)) {
            return false;
        }

        if (!empty($orderId)) {
            $this->order = $this->orderRepository->get($orderId);
        } 
        
        if (!empty($this->quoteId)) {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $this->cartRepositoryInterface->get($this->quoteId);
            $this->order = $this->orderModel->loadByIncrementId($quote->getReservedOrderId());
        }

        $this->configProvider->setOrder($this->order);

        $this->paymentMethodCode = $this->order->getPayment()->getMethod();
        $this->args = $this->configProvider->getConfig()[$this->paymentMethodCode]['config'];

        $this->baseURL = $this->storeManager->getStore()->getBaseUrl();

        $this->testMode = !!$this->cookieManager->getCookie('lunar_testmode');

        $privateKey =  $this->getStoreConfigValue('app_key');

        /** API Client instance */
        $this->apiClient = new Lunar($privateKey, null, $this->testMode);

        return true;
    }

    /**
     *
     */
    public function execute()
    {
        if ($this->request->getParam('quote_id')) {
            return $this->redirectToHostedCheckout();

        } else {
            return $this->processOrderPayment();
        }
    }

    /**
     * 
     */
    private function redirectToHostedCheckout()
    {
        $this->setArgs();

        if (!$this->getPaymentIntent()) {
            try {
                $this->paymentIntentId = $this->apiClient->payments()->create($this->args);
            } catch (\Lunar\Exception\ApiException $e) {
                return $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            }
        }

        if (!$this->paymentIntentId) {
            $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
            return $this->sendJsonResponse(['error' => $errorMessage], 400);
        }

        $this->savePaymentIntent();

        $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
        if (isset($this->args['test'])) {
            $redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
        }

        if ($this->isMultishipping) {
            return $this->response->setRedirect($redirectUrl);
        } else {
            return $this->sendJsonResponse(['paymentRedirectURL' => $redirectUrl]);
        }
    }

    /**
     * 
     */
    private function processOrderPayment()
    {
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

            if ($orderPayment->getAmountAuthorized()) {
                $isAuthorized = true;
            } else {
                $orderPayment->setTransactionId($this->paymentIntentId);
                $orderPayment->setQuotePaymentId($this->order->getQuote()?->getPayment()?->getId());
                $orderPayment->setAmountAuthorized($this->order->getGrandTotal());
            }

            try {
                /** 
                 * We don't need to re-authorize in case someone access 
                 * the return link again, or move back in the browser
                 */
                if (empty($isAuthorized)) {
                    $orderPayment->authorize($isOnline = true, $this->order->getBaseGrandTotal());
                }
            } catch (ApiException $e) {
                return $this->redirectToErrorPage($e->getMessage());
            }

            if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
                
                $orderPayment->capture();

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
     * SET ARGS
     */
    private function setArgs()
    {
        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        } else {
            // Unset 'test' param for live mode
            // @TODO remove this from ConfigProvider when hosted migration will be done
            unset($this->args['test']);
        }

        $this->args['integration'] = [
            'key' => $this->getStoreConfigValue('public_key'),
            'name' => $this->getStoreConfigValue('store_title') ?? $this->storeManager->getStore()->getName(),
            'logo' =>  $this->getStoreConfigValue('logo_url'),
        ];

        if ($this->getStoreConfigValue('configuration_id')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getStoreConfigValue('configuration_id'),
                'logo'            => $this->getStoreConfigValue('logo_url'),
            ];
        }

        if ($this->isMultishipping) {
            $this->args['redirectUrl'] = $this->baseURL . $this->controllerURL . '?multishipping_quote_id=' . $this->quoteId;
        } else {
            unset($this->args['custom']['quoteId']);
            /** Set order increment id to have the same number as in magento admin */
            $this->args['custom'] = array_merge(['orderId' => $this->order->getIncrementId()], $this->args['custom']);
            $this->args['redirectUrl'] = $this->baseURL . $this->controllerURL . '?order_id=' . $this->order->getId();
        }

        $this->args['preferredPaymentMethod'] = $this->paymentMethodCode == ConfigProvider::MOBILEPAY_HOSTED_CODE ? 'mobilePay' : 'card';

        /**
         * Unset some unnecessary args for hosted request
         *
         * @TODO remove them from ConfigProvider when hosted migration will be done
         */
        unset(
            // $this->args['test'],
            $this->args['title'],
            $this->args['amount']['value'],
            $this->args['amount']['exponent'],
            $this->args['checkoutMode'],
        );
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
        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists('transactionid', $additionalInformation)) {
            return $this->paymentIntentId = $additionalInformation['transactionid'];
        }

        return false;
    }
    /**
     *
     */
    private function savePaymentIntent()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->order->getPayment();
        // preserve already existing additional data
        $additionalInformation = $payment->getAdditionalInformation();
        $additionalInformation['transactionid'] = $this->paymentIntentId;
        $payment->setAdditionalInformation($additionalInformation);
        $payment->save();

        // $this->logger->debug("Storing payment intent: " . json_encode($this->paymentIntentId, JSON_PRETTY_PRINT));
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
        // $this->messageManager->addError($errorMessage); // deprecated, but it can render html tags in message
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
        $matchCurrency = $this->args['amount']['currency'] == $transaction['amount']['currency'] ?? null;
        $matchAmount = $this->args['amount']['decimal'] == $transaction['amount']['decimal'] ?? null;

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

    /**
     * @TODO move this into ConfigProvider after complete hosted implementation
     */
    private function getTestObject(): array
    {
        return [
            "card"        => [
                "scheme"  => "supported",
                "code"    => "valid",
                "status"  => "valid",
                "limit"   => [
                    "decimal"  => "39900.95",
                    "currency" => $this->args['amount']['currency'],

                ],
                "balance" => [
                    "decimal"  => "39900.95",
                    "currency" => $this->args['amount']['currency'],

                ]
            ],
            "fingerprint" => "success",
            "tds"         => array(
                "fingerprint" => "success",
                "challenge"   => true,
                "status"      => "authenticated"
            ),
        ];
    }
}
