<?php

namespace Lunar\Payment\Controller\Index;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

use Lunar\Lunar;
use Lunar\Exception\ApiException;
use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 * Controller responsible for redirecting to hosted checkout page
 */
class LunarRedirect implements \Magento\Framework\App\ActionInterface
{
    private const REMOTE_URL = 'https://pay.lunar.money/?id=';
    private const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';
    
    private $configProvider;
    private $storeManager;
    private $scopeConfig;
    private $jsonFactory;
    private $request;
    private $redirectFactory;
    private $response;
    private $messageManager;
    private $cartRepository;
    private $orderPaymentRepository;
    private $cookieManager;
    private $orderModel;
    private $orderCollectionFactory;

    /** @var Order|Quote $order */
    private $order = null;

    private Lunar $apiClient;
    private string $baseURL = '';
    private ?string $quoteId = '';
    private array $args = [];
    private string $paymentIntentId = '';
    private string $paymentMethodCode = '';
    private bool $isMobilePay = false;
    private bool $testMode = false;
    private bool $isMultishipping = false;
    private string $returnURL = 'lunar/index/LunarReturn';


    public function __construct(
        ConfigProvider $configProvider,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        JsonFactory $jsonFactory,
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        Http $response,
        ManagerInterface $messageManager,
        CartRepositoryInterface $cartRepository,
        OrderPaymentRepositoryInterface $orderPaymentRepository,
        CookieManagerInterface $cookieManager,
        Order $orderModel,
        OrderCollectionFactory $orderCollectionFactory
    ) {
        $this->configProvider           = $configProvider;
        $this->storeManager             = $storeManager;
        $this->scopeConfig              = $scopeConfig;
        $this->jsonFactory              = $jsonFactory;
        $this->request                  = $request;
        $this->redirectFactory          = $redirectFactory;
        $this->response                 = $response;
        $this->messageManager           = $messageManager;
        $this->cartRepository           = $cartRepository;
        $this->orderPaymentRepository   = $orderPaymentRepository;
        $this->cookieManager            = $cookieManager;
        $this->orderModel               = $orderModel;
        $this->orderCollectionFactory   = $orderCollectionFactory;

        $this->initialize();
    }

    /**
     *
     */
    public function initialize()
    {
        if (!$this->quoteId = $this->request->getParam('quote_id')) {
            return;
        }
        
        /** @var Quote $quote */
        $quote = $this->cartRepository->get($this->quoteId);
        
        if ('1' == $this->request->getParam('multishipping')) {
            $this->isMultishipping = true;
            /**
             * A trick to get the data in the multishipping flow
             * We have only one quote & quote payment for multiple orders
             * So we'll pay once for all orders
             */
            $this->order = $quote;

        } else {
            $this->order = $this->orderModel->loadByIncrementId($quote->getReservedOrderId());
        }

        $this->configProvider->setOrder($this->order, $isQuote = $this->isMultishipping);

        $this->paymentMethodCode = $this->order->getPayment()->getMethod();
        $this->isMobilePay = $this->paymentMethodCode == ConfigProvider::MOBILEPAY_HOSTED_CODE;

        $this->args = $this->configProvider->getConfig()[$this->paymentMethodCode]['config'];

        $this->baseURL = $this->storeManager->getStore()->getBaseUrl();

        $this->testMode = !!$this->cookieManager->getCookie('lunar_testmode');

        $this->apiClient = new Lunar($this->getStoreConfigValue('app_key'), null, $this->testMode);
    }

    /**
     *
     */
    public function execute()
    {
        if (!$this->quoteId) {
            return $this->sendJsonResponse(['error' => 'No quote ID provided']);
        }

        $this->setArgs();

        if (!$this->getPaymentIntent()) {
            try {
                $this->paymentIntentId = $this->apiClient->payments()->create($this->args);
            } catch (ApiException $e) {
                return $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            }
        }

        if (!$this->paymentIntentId) {
            $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.';
            return $this->sendJsonResponse(['error' => $errorMessage], 400);
        }

        $this->savePaymentIntent();

        $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
        if ($this->testMode) {
            $redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
        }

        if ($this->isMultishipping) {
            return $this->response->setRedirect($redirectUrl);
        } else {
            return $this->sendJsonResponse(['paymentRedirectURL' => $redirectUrl]);
        }
    }

    /**
     * SET ARGS
     */
    private function setArgs()
    {
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
            $this->args['redirectUrl'] = $this->baseURL . $this->returnURL . '?multishipping_quote_id=' . $this->quoteId;
        } else {
            unset($this->args['custom']['quoteId']);
            /** Set order increment id to have the same number as in magento admin */
            $this->args['custom'] = array_merge(['orderId' => $this->order->getIncrementId()], $this->args['custom']);
            $this->args['redirectUrl'] = $this->baseURL . $this->returnURL . '?order_id=' . $this->order->getId();
        }

        $this->args['preferredPaymentMethod'] = $this->isMobilePay ? 'mobilePay' : 'card';
        
        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        }
    }

    /**
     *
     */
    private function getPaymentIntent()
    {
        /** @var \Magento\Sales\Model\Order\Payment|\Magento\Quote\Model\Quote\Payment $payment */
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
        /** @var \Magento\Sales\Model\Order\Payment|\Magento\Quote\Model\Quote\Payment $payment */
        $payment = $this->order->getPayment();

        $additionalInformation = $payment->getAdditionalInformation();
        $additionalInformation['transactionid'] = $this->paymentIntentId;

        $payment->setAdditionalInformation($additionalInformation);

        if ($this->isMultishipping) {
            $this->cartRepository->save($this->order); // order = quote
            // we need to have transactionid set on order payment
            $this->savePaymentIntentOnOrdersPayment();
        } else {
            $this->orderPaymentRepository->save($payment);
        }
    }

    /**
     *
     */
    private function savePaymentIntentOnOrdersPayment()
    {
        $quoteOrders = $this->orderCollectionFactory->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter(
                'quote_id',
                ['eq' => $this->order->getId()] // order = quote
        );

        foreach ($quoteOrders as $order) {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $order->getPayment();
    
            $additionalInformation = $orderPayment->getAdditionalInformation();
            $additionalInformation['transactionid'] = $this->paymentIntentId;
    
            $orderPayment->setAdditionalInformation($additionalInformation);
            $this->orderPaymentRepository->save($orderPayment);
        }
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
