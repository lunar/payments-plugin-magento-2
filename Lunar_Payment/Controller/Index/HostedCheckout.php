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
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Lunar;

/**
 * Controller responsible to manage Hosted Checkout payments
 * 
 * NOTE: for multishipping flow, we set the quote in $this->order property
 * @TODO change the logic to use interchangeable order/quote objects 
 */
class HostedCheckout implements \Magento\Framework\App\ActionInterface
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

    private $invoiceCollectionFactory;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;
    private $cookieManager;

    const REMOTE_URL = 'https://pay.lunar.money/?id=';
    const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

    private Lunar $lunarApiClient;
    private string $transactionId = '';
    private $intentIdKey = '_lunar_intent_id';

    private string $baseURL = '';
    private bool $isInstantMode = false;
    private $quotePayment = null;

    // private ?Order $order = null;
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
        Order $orderModel,
        Quote $quote,
        
        CollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        PriceCurrencyInterface $priceCurrencyInterface,
        CookieManagerInterface $cookieManager
    ) {
        
        $this->storeManager           = $storeManager;
        $this->logger                 = $logger;
        $this->scopeConfig            = $scopeConfig;
        $this->orderRepository        = $orderRepository;
        $this->jsonFactory            = $jsonFactory;
        $this->request                = $request;
        $this->redirectFactory        = $redirectFactory;
        $this->response               = $response;
        $this->messageManager         = $messageManager;

        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
        $this->priceCurrencyInterface = $priceCurrencyInterface;
        $this->cookieManager = $cookieManager;

        /**
         * If request has order_id or multishipping_quote_id, the request is a redirect from hosted page
         */
        if ($orderId = $this->request->getParam('order_id')) {

            $this->order = $this->orderRepository->get($orderId);
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $cartRepositoryInterface->get($this->order->getQuoteId());
            $this->quotePayment = $quote->getPayment();
        
        } else if ($quoteId = $this->request->getParam('multishipping_quote_id')) {
            $this->isMultishipping = true;
            
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $cartRepositoryInterface->get($quoteId);
            $this->quotePayment = $quote->getPayment();
            
            $this->order = $quote;
            
        } else if ('1' == $this->request->getParam('multishipping')) {
            $this->isMultishipping = true;

            $quote = $cartRepositoryInterface->get($this->request->getParam('quote_id'));

            $this->order = $quote;
            
        } else if ($quoteId = $this->request->getParam('quote_id')) {
            $quote = $cartRepositoryInterface->get($quoteId);
            $this->order = $orderModel->loadByIncrementId($quote->getReservedOrderId());

        
        } else {
            return $this->sendJsonResponse(['error' => true]);
        }

        $configProvider->setOrder($this->order, $this->isMultishipping);

        $this->paymentMethodCode = $this->order->getPayment()->getMethod();
        $this->args = $configProvider->getConfig()[$this->paymentMethodCode]['config'];

        $this->baseURL = $this->storeManager->getStore()->getBaseUrl();
        $this->isInstantMode = (CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'));

        $this->testMode = !!$this->cookieManager->getCookie('lunar_testmode');

        $this->publicKey =  $this->getStoreConfigValue('public_key');
        $privateKey =  $this->getStoreConfigValue('app_key');

        /** API Client instance */
        $this->lunarApiClient = new Lunar($privateKey, null, $this->testMode);
    }


    /**
     * EXECUTE
     */
    public function execute()
    { 
        /** 
         * First controller call
         */
        if ($this->request->getParam('quote_id')) {

            $this->setArgs();

            /** @TODO is this check necessary here? */
            if (! $this->getPaymentIntentFromOrder()) {
                try {
                    $this->paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
                } catch(\Lunar\Exception\ApiException $e) {
                    return $this->sendJsonResponse(['error' => $e->getMessage()], 400);
                }
            }
    
            if (! $this->paymentIntentId) {
                $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
                return $this->sendJsonResponse(['error' => $errorMessage], 400);
            }
  
            $this->savePaymentIntentOnOrder();
    
            $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
            if(isset($this->args['test'])) {
                $redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
            }

            if ($this->isMultishipping) {
                return $this->response->setRedirect($redirectUrl);
            } else {
                return $this->sendJsonResponse([
                    'paymentRedirectURL' => $redirectUrl,
                ]);
            }



        /** 
         * After callback redirect
         */
        } else {
            $transaction = $this->lunarApiClient->payments()->fetch($this->getPaymentIntentFromOrder());

            $result = $this->parseApiTransactionResponse($transaction);

            if (! $result) {
                return $this->redirectToErrorPage($this->getResponseError($transaction));
            }

            $this->transactionId = $transaction['id'];


            if ($this->isMultishipping) {
                $orders = $this->getOrderCollectionByQuoteId($this->order->getId());

                foreach ($orders as $this->order) {
                    $this->finalizeOrder();
                }
            } else {
                $this->finalizeOrder();
            }


            $dataRedirectUrl = $this->storeManager->getStore()->getBaseUrl();
            if ($this->isMultishipping) {
                // $dataRedirectUrl .= 'multishipping/checkout/success';
                $dataRedirectUrl .= 'lunar/index/MultishippingSuccess';
            } else {
                $dataRedirectUrl .= 'checkout/onepage/success';
            }

            return $this->response->setRedirect($dataRedirectUrl);
        }
    }


    private function finalizeOrder()
    {
        /** Update info on order payment */
        $this->setTxnIdOnQuotePayment();
        $this->setTxnIdOnOrderPayment();

        if ($this->isInstantMode) {
            // the order state will be changed after invoice creation
            $this->createInvoiceForOrder();
        } else {
            /**
            * @see https://magento.stackexchange.com/questions/225524/magento-2-show-pending-payment-order-in-store-front/280227#280227
            * Important note for Pending Payments
            * If you have a "pending payment" status order,
            * Magento 2 will cancel the order automatically after 8 hours if the payment status doesn't change.
            * To change that, go to Stores > Configuration > Sales > Order Cron Settings
            * and change the Lifetime to a greater value.
            *
            * If pending_payment orders not show in front, @see https://magento.stackexchange.com/a/225531/100054
            */
            $this->insertNewTransactionForOrderPayment();
            $this->order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
        }

        $this->orderRepository->save($this->order);
    }

    /**
     * SET ARGS
     */
    private function setArgs()
    {
        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
            // $this->args['test'] = new \stdClass();
        } else {
            // Unset 'test' param for live mode
            unset($this->args['test']);
        }

        $this->args['integration'] = [
            'key' => $this->publicKey,
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
            $this->args['redirectUrl'] = $this->baseURL . $this->controllerURL . '?multishipping_quote_id=' . $this->order->getId();
        } else {
            unset($this->args['custom']['quoteId']);
            /** Set order increment id to have the same number as in magento admin */
            $this->args['custom'] = array_merge(['orderId' => $this->order->getIncrementId()], $this->args['custom']);
            $this->args['redirectUrl'] = $this->baseURL . $this->controllerURL . '?order_id=' . $this->order->getId();
        }
        
        $this->args['preferredPaymentMethod'] = $this->paymentMethodCode == ConfigProvider::MOBILEPAY_HOSTED_CODE ? 'mobilePay' : 'card';

        /** 
         * Unset some unnecessary args for hosted request
         * @TODO remove them from ConfigProvider when hosted migration will be done
         */
        unset(
            // $this->args['test'],
            $this->args['title'],
            $this->args['locale'],
            $this->args['amount']['value'],
            $this->args['amount']['exponent'],
            $this->args['checkoutMode'],
            $this->args['paymentMethod'],
        );
    }

    /**
     * 
     */
    private function setTxnIdOnQuotePayment()
    {
        try {
            $additionalInformation = ['transactionid' => $this->transactionId] + $this->quotePayment->getAdditionalInformation();
            $this->quotePayment->setAdditionalInformation($additionalInformation);
            $this->quotePayment->save();

        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            $this->redirectToErrorPage(__('Something went wrong saving transaction ID on quote payment'));
        }
    }

    /**
     * 
     */
    private function getOrderCollectionByQuoteId($quoteId)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $orderCollectionFactory = $objectManager->get(\Magento\Sales\Model\ResourceModel\Order\CollectionFactory::class);

        $collection = $orderCollectionFactory->create()
          ->addFieldToSelect('*')
          ->addFieldToFilter('quote_id',
                 ['eq' => $quoteId]
             );
  
      return $collection;
 
     }

    /**
     *
     */
    private function setTxnIdOnOrderPayment()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();
    
            $baseGrandTotal = $this->order->getBaseGrandTotal();
            $grandTotal = $this->order->getGrandTotal();
    
            $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
            $orderPayment->setAmountAuthorized($grandTotal);
            // $orderPayment->setAdditionalInformation('transactionid', $this->transactionId);
            $additionalInformation = ['transactionid' => $this->transactionId] + $orderPayment->getAdditionalInformation();
            $orderPayment->setAdditionalInformation($additionalInformation);
            $orderPayment->setLastTransId($this->transactionId);
            $orderPayment->setQuotePaymentId($this->quotePayment->getId());
            $orderPayment->save();

        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            $this->redirectToErrorPage(__('Something went wrong saving transaction ID on payment'));
        }
    }

    /**
     * 
     */
    private function insertNewTransactionForOrderPayment()
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            $orderPayment->setTransactionId($this->transactionId);
            $orderPayment->setIsTransactionClosed(0);
            $orderPayment->setShouldCloseParentTransaction(0);
            $transaction = $orderPayment->addTransaction(TransactionInterface::TYPE_AUTH, null, $failSafe = true);

            $commentContent = 'Authorized amount of ' . $this->getFormattedPriceWithCurrency() . '.';
            $orderPayment->addTransactionCommentsToOrder($transaction, $commentContent);

        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            $this->redirectToErrorPage(__('Something went wrong adding new transaction for payment'));
        }
    }

    /**
     *
     */
    private function getPaymentIntentFromOrder()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists($this->intentIdKey, $additionalInformation)) {
            return $this->paymentIntentId = $additionalInformation[$this->intentIdKey];
        }

        return false;
    }
    /**
     *
     */
    private function savePaymentIntentOnOrder()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->order->getPayment();
        // preserve already existing additional data
        $additionalInformation = $payment->getAdditionalInformation();
        $additionalInformation[$this->intentIdKey] = $this->paymentIntentId;
        $payment->setAdditionalInformation($additionalInformation);
        $payment->save();

        // $this->logger->debug("Storing payment intent: " . json_encode($this->paymentIntentId, JSON_PRETTY_PRINT));
    }

    /**
     *
     */
    private function createInvoiceForOrder()
    {
        $invoiceEmailMode =  $this->getStoreConfigValue('invoice_email');

        try {
            $invoices = $this->invoiceCollectionFactory->create()
                ->addAttributeToFilter('order_id', array('eq' => $this->order->getId()));
            $invoices->getSelect()->limit(1);

            if ((int)$invoices->count() !== 0) {
                return null;
            }

            if (!$this->order->canInvoice()) {
                return null;
            }

            $invoice = $this->invoiceService->prepareInvoice($this->order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);
            $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();

            if (!$invoice->getEmailSent() && $invoiceEmailMode == 1) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                    // Do something if failed to send
                }
            }
        } catch (\Exception $e) {
            $this->order->addCommentToStatusHistory('Exception message: ' . $e->getMessage(), false);
            $this->orderRepository->save($this->order);
            return null;
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
     * Parses api transaction response for errors
     */
    protected function parseApiTransactionResponse($transaction)
    {
        if (! $this->isTransactionSuccessful($transaction)) {
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
        $matchCurrency = $this->args['amount']['currency'] == $transaction['amount']['currency'];
        $matchAmount = $this->args['amount']['decimal'] == $transaction['amount']['decimal'];

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     * @param array $result The result returned by the api wrapper.
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
	 * 
	 */
	private function getFormattedPriceWithCurrency($useOrderCurrency = false) 
    {
        $orderTotal = $this->order->getBaseGrandTotal();
        $currency = $this->order->getBaseCurrencyCode();

        if ($useOrderCurrency) {
            $orderTotal = $this->order->getGrandTotal();
            $currency = $this->order->getOrderCurrencyCode();
        }

        return $this->priceCurrencyInterface->format(
            $orderTotal, 
            $includeContainer = false, 
            $precision = 2, 
            $scope = null,
            $currency
        );
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
                    "decimal"  => "25000.99",
                    "currency" => $this->args['amount']['currency'],
                    
                ],
                "balance" => [
                    "decimal"  => "25000.99",
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
