<?php

namespace Lunar\Payment\Controller\Index;

use Psr\Log\LoggerInterface;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Response\Http;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Pricing\PriceCurrencyInterface;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;

/**
 * Controller responsible to manage MobilePay payments
 */
class MobilePayPayment implements ActionInterface
{
    private $configProvider;
    private $storeManager;
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $jsonFactory;
    private $requestInterface;
    private $redirect;
    private $redirectFactory;
    private $response;
    private $messageManager;
    private $orderStatusRepository;
    private $invoiceCollectionFactory;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;

    const REMOTE_URL = 'https://b.paylike.io';

    private string $mobilePayCode = ConfigProvider::MOBILEPAY_CODE;
    private $hintsOrderKey = 'lunarmobilepay_hints';
    private Order $order;
    private bool $isInstantMode = false;
    private $orderId = null;
    private array $args = [];
    private string $referer = '';
    private string $orderBaseUrl = '';
    private bool $beforeOrder = true;
    private string $authorizationId = '';


    public function __construct(
        ConfigProvider $configProvider,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        JsonFactory $jsonFactory,
        RequestInterface $requestInterface,
        RedirectInterface $redirect,
        RedirectFactory $redirectFactory,
        Http $response,
        ManagerInterface $messageManager,
        OrderStatusHistoryRepositoryInterface $orderStatusRepository,

        CollectionFactory $invoiceCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        PriceCurrencyInterface $priceCurrencyInterface
    ) {
        $this->configProvider         = $configProvider;
        $this->storeManager           = $storeManager;
        $this->logger                 = $logger;
        $this->scopeConfig            = $scopeConfig;
        $this->orderRepository        = $orderRepository;
        $this->jsonFactory            = $jsonFactory;
        $this->requestInterface       = $requestInterface;
        $this->redirect               = $redirect;
        $this->redirectFactory        = $redirectFactory;
        $this->response               = $response;
        $this->messageManager         = $messageManager;
        $this->orderStatusRepository = $orderStatusRepository;

        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
        $this->priceCurrencyInterface = $priceCurrencyInterface;

        $this->isInstantMode = (CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'));

        $baseUrl = $this->storeManager->getStore()->getBaseUrl();

        /**
         * If request has order_id, the request is from a redirect (after_order)
         */
        if ($requestInterface->getParam('order_id')) {

            $this->orderId = $requestInterface->getParam('order_id');

            $this->beforeOrder = false;

            $this->order = $this->orderRepository->get($this->orderId);
            $this->configProvider->setOrder($this->order);

            $configData = $this->configProvider->getConfig()[$this->mobilePayCode]['config'];

            $this->args['amount'] = $configData['amount'];
            $this->args['custom'] = $configData['custom'];

            /** Set order Id instead of quote id, when after_order flow */
            unset($this->args['custom']['quoteId']);
            $this->args['custom'] = array_merge(['orderId' => $this->order->getIncrementId()], $this->args['custom']);

            $this->referer = $baseUrl . 'lunar/index/MobilePayPayment/?order_id=' . $this->orderId;
        }
        else {
            $this->args = $requestInterface->getParam('args');
        }
    }

    /**
     * EXECUTE
     */
    public function execute()
    {
        $this->getHintsFromOrder();

        $this->setArgs();

        $response = $this->mobilePayPayment();

        if (isset($response['error'])) {
            if ($this->beforeOrder) {
                return $this->sendJsonResponse($response);
            } else {
                $errorMessage = $response['error'] . '.<br> Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>';
                return $this->redirectToErrorPage($errorMessage);
            }
        }

        $this->authorizationId = $response['data']['authorizationId'] ?? '';

        if($this->authorizationId) {
            /**
             * Before order, send json response to front component
             */
            if ($this->beforeOrder) {
                return $this->sendJsonResponse($response);
            }

            /**
             * After order, redirect to success after set trxid on quote payment and capture if instant mode.
             */

            /** Update info on order payment */
            $this->setTxnIdOnOrderPayment();
            $this->updateLastOrderStatusHistory();


            if ($this->isInstantMode) {
                // the order state will be changed after invoice creation
                $this->createInvoiceForOrder();
            }
            else {
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
                $this->order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
            }

            $this->order->save();

            $dataRedirectUrl = $this->storeManager->getStore()->getBaseUrl() . 'checkout/onepage/success';
            return $this->response->setRedirect($dataRedirectUrl);
        }

        if (($response['data']['type'] ?? '') === 'redirect' ) {
            $dataRedirectUrl = $response['data']['url'];
            return $this->response->setRedirect($dataRedirectUrl);
        }

        /**
         * Redirect to error page if response is iframe & checkout mode is after_order
         */
        if (
            ! $this->beforeOrder
            && isset($response['data']['type'])
            && ($response['data']['type'] === 'iframe')
        ) {
            $errorMessage = 'An error occured in server response. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
            return $this->redirectToErrorPage($errorMessage);
        }

        return $this->jsonFactory->create()->setData(['data' => $response['data']]);
    }

    /**
     * SET TXN ID ON ORDER PAYMENT
     */
    private function setTxnIdOnOrderPayment()
    {
        $orderPayment = $this->order->getPayment();

        $baseGrandTotal = $this->order->getBaseGrandTotal();
        $grandTotal = $this->order->getGrandTotal();

        $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
        $orderPayment->setAmountAuthorized($grandTotal);
        $orderPayment->setAdditionalInformation('transactionid', $this->authorizationId);
        $orderPayment->setLastTransId($this->authorizationId);
        $orderPayment->save();

        /** Manually insert transaction if after_order & delayed mode. */
        if (! $this->beforeOrder && ! $this->isInstantMode) {
            $this->insertNewTransactionForPayment($orderPayment);
        }
    }

    /**
     * INSERT NEW TRANSACTION FOR PAYMENT
     */
    private function insertNewTransactionForPayment($orderPayment)
    {
        /**
         * @TODO can we use authorize() from Payment model ? (inherited by our model)
         * @see vendor\magento\module-sales\Model\Order\Payment.php L1134
         * in that case we can remove some of the methods here and use only one (?)
         */
        $orderPayment->setTransactionId($this->authorizationId);
        $orderPayment->setIsTransactionClosed(0);
        $orderPayment->setShouldCloseParentTransaction(0);
        //  $paymentTransaction = $orderPayment->_addTransaction('authorization', null, true); // true - failsafe
         $paymentTransaction = $orderPayment->addTransaction(TransactionInterface::TYPE_AUTH);
    }

    /**
     * UPDATE LAST ORDER STATUS HISTORY
     */
    private function updateLastOrderStatusHistory()
    {
        $statusHistories = $this->order->getStatusHistoryCollection()->toArray()['items'];

        /** Get only last created history */
        $orderHistory = $statusHistories[0] ?? null;

        if ( ! $orderHistory) {
            return;
        }

        $commentContentModified = str_replace('trxid_placeholder', $this->authorizationId, $orderHistory['comment'] ?? '');

        
        /** @var \Magento\Sales\Model\Order\Status\History $historyItem */
        $historyItem = $this->orderStatusRepository->get($orderHistory['entity_id']);


        if ( ! $historyItem) {
            return;
        }

        /** Delete last order status history if conditions met. */
        if ( ! $this->beforeOrder) {
            if ($this->isInstantMode) {
                $historyItem->delete();
                return;
            } else {
                $baseGrandTotal = $this->order->getBaseGrandTotal();
                $formattedPrice = $this->priceCurrencyInterface->format(
                    $baseGrandTotal, 
                    $includeContainer = false, 
                    $precision = 2, 
                    $scope = null,
                    $currency = $this->order->getBaseCurrencyCode()
                );

                /** The price will be displayed in base currency. */
                $commentContentModified = 'Authorized amount of ' . $formattedPrice . '. Transaction ID: "' . $this->authorizationId . '".';
                $historyItem->setIsCustomerNotified(0); // @TODO check this (is notified? should we notify?)
            }
        }

        $historyItem->setStatus(Order::STATE_PENDING_PAYMENT);
        $historyItem->setComment($commentContentModified);
        $historyItem->save();

    }

    /**
     * SET ARGS
     */
    private function setArgs()
    {
        $publicKey = $this->getStoreConfigValue('live_public_key');

        if ('test' == $this->getStoreConfigValue('transaction_mode')) {
            $publicKey = $this->getStoreConfigValue('test_public_key');
            $this->args['test'] = new \stdClass();
        }  else {
            // Unset 'test' param for live mode
            unset($this->args['test']);
        }

        $this->args['integration'] = ['key' => $publicKey];

        $this->args['mobilepay'] = [
            'configurationId' => $this->getStoreConfigValue('configuration_id'),
            'logo'            => $this->getStoreConfigValue('logo_url'),
        ];

        if ($this->referer) {
            $returnUrl = $this->referer;
        } else {
            /** Checkout payment step url */
            $returnUrl = $this->redirect->getRefererUrl() . '/#payment'; // or $this->redirect->getRedirectUrl();
        }

        if ($returnUrl && !$this->beforeOrder) {
            $this->args['mobilepay']['returnUrl'] = $returnUrl;
        }

        $this->args['hints'] = $this->args['hints'] ?? [];

        $this->args['amount']['exponent'] = (int) ($this->args['amount']['exponent'] ?? 0);
    }

    /**
     * MOBILEPAY PAYMENT
     */
    private function mobilePayPayment()
    {
        /**
        * Request
        */
        $response = $this->request('/payments');

        if ( ! $response) {
            return $this->error('There was an error. Please try again later');
        }

        if (isset($response['authorizationId'])) {
            return $this->success($response);
        }


        if (!isset($response['challenges'])) {
            return $this->error('Payment failed');
        }

        $challengeResponse = $this->handleFirstChallenge($response['challenges']);


        if ($challengeResponse['error'] ?? '') {
            return $this->error('There was an error. Please try again later');
        }

        if (!$challengeResponse) {
            return $this->mobilePayPayment();
        }

        $challengeResponse['hints'] = $this->args['hints'];

        return $this->success($challengeResponse);
    }

    /**
     * ERROR
     */
    private function error($message)
    {
        return ['error' => $message];
    }

    /**
     * SUCCESS
     */
    private function success($data)
    {
        return ['data' => $data];
    }

    /**
     * HANDLE FIRST CHALLENGE
     */
    protected function handleFirstChallenge($challenges)
    {
        $challenge = $challenges[0]; // we prioritize the first one always

        if (count($challenges) > 1) {
            if ($this->beforeOrder) {
                $challenge = $this->searchForChallenge($challenges, 'iframe');
            } else {
                $challenge = $this->searchForChallenge($challenges, 'redirect');
            }

            if (!$challenge) {
                $challenge = $challenges[0];
            }
        }

        /**
         * Request
         */
        $response = $this->request($challenge['path']);

        if ($response['error'] ?? '') {
            return $this->error($response['error']);
        }

        if (isset($response['code']) && isset($response['message'])) {
            return $this->error($response['message']);
        }

        if ( ! isset($response['hints']) && isset($data['notBefore']) ) {
            $notBefore = \DateTime::createFromFormat('Y-m-d\TH:i:s+', $response['notBefore']);
			$now = new \DateTime();
			$timeDiff = ($notBefore->getTimestamp() - $now->getTimestamp()) + 1; // add 1 second to account for miliseconds loss

            if ($timeDiff > 0) {
                sleep($timeDiff);
            }

            return $this->handleFirstChallenge($challenges);
        }

        $this->args['hints'] = array_merge($this->args['hints'], $response['hints']);

        $this->saveHintsOnOrder();

        switch ($challenge['type']) {
            case 'fetch':
            case 'poll':
                return [];
                break;

            case 'redirect':
                $response['type'] = $challenge['type'];
                // store hints for this order for 30 minutes
                return $response;
                break;

            case 'iframe':
            case 'background-iframe':
                $response['type'] = $challenge['type'];
                return $response;
                break;

            default:
                return $this->error('Unknown challenge type: ' . $challenge['type']);
        }

        return $response;
    }


    /**
     * SEARCH FOR CHALLENGE
     */
    protected function searchForChallenge($challenges, $type)
    {
        $this->logger->debug(json_encode($challenges, JSON_PRETTY_PRINT));

        foreach ($challenges as $challenge) {
            if ($challenge['type'] === $type) {
                return $challenge;
            }
        }

        return false;
    }

    /**
     * REQUEST
     */
    protected function request($path)
    {
        $this->logger->debug("Calling $path with hints: " . json_encode($this->args['hints'] ?? [], JSON_PRETTY_PRINT));

        /** Unset some unnecessary args */
        unset(
            $this->args['title'],
            $this->args['locale'],
            $this->args['checkoutMode'],
            $this->args['amount']['decimal']
        );

        $response = $this->makeCurlRequest(
            $path,
            Request::HTTP_METHOD_POST,
            $params = [
                'headers' => [
                    'Content-Type'   => "application/json",
                    'Accept-Version' => 4
                ],
                'version' => '1.0',
                'body'        => json_encode($this->args),
                'redirection' => 5,
                'timeout'     => 45,
                'blocking'    => true,
                'cookies'     => []
            ]
        );

        $this->logger->debug("Response: " . json_encode($response, JSON_PRETTY_PRINT));

        return $response;
    }


    /**
     * MAKE CURL REQUEST
     */
    private function makeCurlRequest(
        string $uriEndpoint,
        string $requestMethod = Request::HTTP_METHOD_GET,
        array $params = []
    ) {
        try {
            $guzzleClient = new GuzzleClient([
                    'base_uri' => self::REMOTE_URL,
                    'allow_redirects' => true,
            ]);

            $response = $guzzleClient->request($requestMethod, $uriEndpoint, $params);
            $response = json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $exception) {
            $response = ['error' => $exception->getMessage()];
        }

        return $response;
    }

    /**
     *
     */
    private function getHintsFromOrder()
    {
        if ($this->beforeOrder) {
            return;
        }

        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();
        $orderHints = [];
        if ($additionalInformation && array_key_exists($this->hintsOrderKey, $additionalInformation)) {
            $orderHints = $additionalInformation[$this->hintsOrderKey];
        }

        if ($orderHints) {
            $this->args['hints'] = $orderHints;
        }
    }

    /**
     *
     */
    private function saveHintsOnOrder()
    {
        if ($this->beforeOrder) {
            return;
        }

        // preserve already existing additional data
        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();
        $additionalInformation[$this->hintsOrderKey] = $this->args['hints'];
        $payment->setAdditionalInformation($additionalInformation);
        $payment->save();

        $this->logger->debug("Storing hints: " . json_encode($this->args['hints'], JSON_PRETTY_PRINT));
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
            $this->order->addStatusHistoryComment('Exception message: ' . $e->getMessage(), false); // addStatusHistoryComment() is deprecated !
            $this->order->save(); // save() is deprecated !
            return null;
        }
    }

    /**
     *
     */
    private function getStoreConfigValue($configKey)
    {
        /** This is not imperative to be used. It works even without it */
        $storeId = $this->storeManager->getStore()->getId();

        /** "path" is composed based on etc/adminhtml/system.xml as "section_id/group_id/field_id" */
        $configPath = 'payment/' . $this->mobilePayCode . '/' . $configKey;

        return $this->scopeConfig->getValue(
            /*path*/ $configPath,
            /*scopeType*/ ScopeInterface::SCOPE_STORE,
            /*scopeCode*/ $storeId
        );
    }

    /**
     *
     */
    private function sendJsonResponse($response, $code = 200)
    {
        return $this->jsonFactory->create()->setData($response);
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
}
