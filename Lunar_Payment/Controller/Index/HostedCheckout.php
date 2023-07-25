<?php

namespace Lunar\Payment\Controller\Index;

use Psr\Log\LoggerInterface;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Response\Http;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;

use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Pricing\PriceCurrencyInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Lunar;

/**
 * Controller responsible to manage Hosted Checkout payments
 */
class HostedCheckout implements \Magento\Framework\App\ActionInterface
{
    private $configProvider;
    private $storeManager;
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $jsonFactory;
    private $redirect;
    private $redirectFactory;
    private $response;
    private $messageManager;
    private $orderStatusRepository;
    private $cartRepositoryInterface;
    private $invoiceCollectionFactory;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;

    const REMOTE_URL = 'https://pay.lunar.money/?id=';
    const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

    private Lunar $lunarApiClient;
    private $intentIdKey = '_lunar_intent_id';

    private string $baseURL = '';
    private bool $isInstantMode = false;
    private $quote = null;
    private bool $beforeOrder = true;
    private Order $order;
    private array $args = [];
    private string $paymentIntentId = '';
    private string $controllerURL = 'lunar/index/HostedCheckout';
    private string $paymentMethodCode = '';
    private string $publicKey = '';


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
        CartRepositoryInterface $cartRepositoryInterface,

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

        $this->baseURL = $this->storeManager->getStore()->getBaseUrl();

        /**
         * If request has order_id, the request is from a redirect
         */
        if ($orderId = $requestInterface->getParam('order_id')) {

            $this->beforeOrder = false;

            $this->order = $this->orderRepository->get($orderId);
            $this->configProvider->setOrder($this->order);
            $this->paymentMethodCode = $this->order->getPayment()->getMethod();

            $configData = $this->configProvider->getConfig()[$this->paymentMethodCode]['config'];

            $this->args['amount'] = $configData['amount'];
            $this->args['custom'] = $configData['custom'];

            /** Set order Id instead of quote id, when after_order flow */
            unset($this->args['custom']['quoteId']);
            $this->args['custom'] = array_merge(['orderId' => $this->order->getIncrementId()], $this->args['custom']);
        } else {
            $this->args = $requestInterface->getParam('args');
            $this->paymentMethodCode = $this->args['custom']['paymentMethod'];
        }


        if ('test' == $this->getStoreConfigValue('transaction_mode')) {
            $this->publicKey =  $this->getStoreConfigValue('test_public_key');
            $privateKey =  $this->getStoreConfigValue('test_app_key');
        } else {
            $this->publicKey = $this->getStoreConfigValue('live_public_key');
            $privateKey = $this->getStoreConfigValue('live_app_key');
        }

        /** API Client instance */
        $this->lunarApiClient = new Lunar($privateKey);
    }


    /**
     * EXECUTE
     */
    public function execute()
    {
        $this->setArgs();

        if (!$this->checkPaymentIntentOnOrder()) {
            $this->paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
        }

        if (! $this->paymentIntentId) {
            $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
            return $this->redirectToErrorPage($errorMessage);
        }

        $redirectUrl = self::REMOTE_URL . $this->paymentIntentId;
        if(isset($this->args['test'])) {
			$redirectUrl = self::TEST_REMOTE_URL . $this->paymentIntentId;
		}
        // return $this->response->setRedirect($redirectUrl);
        $this->sendJsonResponse([
            'data' => [
                'paymentRedirectURL' => $redirectUrl
            ],
        ]);



        // $this->authorizationId = $response['data']['authorizationId'] ?? '';

        // if($this->authorizationId) {
        //     /**
        //      * Before order, send json response to front component
        //      */
        //     if ($this->beforeOrder) {
        //         return $this->sendJsonResponse($response);
        //     }

        //     /**
        //      * After order, redirect to success after set trxid on quote payment and capture if instant mode.
        //      */

        //     /** Update info on order payment */
        //     $this->setTxnIdOnOrderPayment();
        //     $this->updateLastOrderStatusHistory();


        //     if ($this->isInstantMode) {
        //         // the order state will be changed after invoice creation
        //         $this->createInvoiceForOrder();
        //     }
        //     else {
        //         /**
        //          * @see https://magento.stackexchange.com/questions/225524/magento-2-show-pending-payment-order-in-store-front/280227#280227
        //          * Important note for Pending Payments
        //          * If you have a "pending payment" status order,
        //          * Magento 2 will cancel the order automatically after 8 hours if the payment status doesn't change.
        //          * To change that, go to Stores > Configuration > Sales > Order Cron Settings
        //          * and change the Lifetime to a greater value.
        //          *
        //          * If pending_payment orders not show in front, @see https://magento.stackexchange.com/a/225531/100054
        //          */
        //         $this->order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
        //     }

        //     $this->order->save();

        //     $dataRedirectUrl = $this->storeManager->getStore()->getBaseUrl() . 'checkout/onepage/success';
        //     return $this->response->setRedirect($dataRedirectUrl);
        // }

        // if (($response['data']['type'] ?? '') === 'redirect' ) {
        //     $dataRedirectUrl = $response['data']['url'];
        //     return $this->response->setRedirect($dataRedirectUrl);
        // }

        // /**
        //  * Redirect to error page if response is iframe & checkout mode is after_order
        //  */
        // if (
        //     ! $this->beforeOrder
        //     && isset($response['data']['type'])
        //     && ($response['data']['type'] === 'iframe')
        // ) {
        //     $errorMessage = 'An error occured in server response. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
        //     return $this->redirectToErrorPage($errorMessage);
        // }

        // return $this->jsonFactory->create()->setData(['data' => $response['data']]);
    }

    /**
     * SET ARGS
     */
    private function setArgs()
    {
        if ('test' == $this->getStoreConfigValue('transaction_mode')) {
            $this->args['test'] = new \stdClass();
            // unset($this->args['test']);
        } else {
            // Unset 'test' param for live mode
            unset($this->args['test']);
        }

        $this->args['integration'] = [
            'key' => $this->publicKey,
            'name' => $this->storeManager->getStore()->getName(),
            'logo' =>  $this->getStoreConfigValue('logo_url'),
        ];

        if ($this->getStoreConfigValue('configuration_id')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationId' => $this->getStoreConfigValue('configuration_id'),
                'logo'            => $this->getStoreConfigValue('logo_url'),
            ];
        }

        // $this->args['amount']['decimal'] = (string) ($this->args['amount']['value'] / 10 ** $this->args['amount']['exponent']) ?? 0;
        // $this->args['custom']['orderId'] = $this->args['custom']['quoteId'];

        // $this->args['redirectUrl'] = $this->storeManager->getStore()->getBaseUrl() . 'checkout/onepage/success';

        $params = $this->beforeOrder ? '?quote_id=' : '?order_id=';
        $this->args['redirectUrl'] = $this->baseURL . $this->controllerURL . $params . $this->args['custom']['quoteId'];
        $this->args['preferredPaymentMethod'] = $this->paymentMethodCode == ConfigProvider::MOBILEPAY_HOSTED_CODE ? 'mobilePay' : 'card';

        /** Unset some unnecessary args */
        unset(
            // $this->args['test'],
            $this->args['title'],
            $this->args['locale'],
            $this->args['amount']['value'],
            $this->args['amount']['exponent'],
            $this->args['checkoutMode'],
            // $this->args['custom']['quoteId']
        );

        // unset($this->args['test']);
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
     * SET TXN ID ON ORDER PAYMENT
     */
    private function setTxnIdOnOrderPayment()
    {
        /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
        $orderPayment = $this->order->getPayment();

        $baseGrandTotal = $this->order->getBaseGrandTotal();
        $grandTotal = $this->order->getGrandTotal();

        $orderPayment->setBaseAmountAuthorized($baseGrandTotal);
        $orderPayment->setAmountAuthorized($grandTotal);
        $orderPayment->setAdditionalInformation('transactionid', $this->paymentIntentId);
        $orderPayment->setLastTransId($this->paymentIntentId);
        $orderPayment->save();

        /** Manually insert transaction if after_order & delayed mode. */
        if (!$this->beforeOrder && !$this->isInstantMode) {
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
        $orderPayment->setTransactionId($this->paymentIntentId);
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

        if (!$orderHistory) {
            return;
        }

        $commentContentModified = str_replace('trxid_placeholder', $this->paymentIntentId, $orderHistory['comment'] ?? '');

        $historyItem = $this->orderStatusRepository->get($orderHistory['entity_id']);


        if ( ! $historyItem) {
            return;
        }

        /** Delete last order status history if conditions met. */
        if (!$this->beforeOrder) {
            if ($this->isInstantMode) {
                $historyItem->delete();
                return;
            } else {
                /** The price will be displayed in base currency. */
                $baseGrandTotal = $this->order->getBaseGrandTotal();
                $formattedPrice = $this->priceCurrencyInterface->format($baseGrandTotal, $includeContainer = false, $precision = 2, $scope = null, $currency = 'USD');
                $commentContentModified = 'Authorized amount of ' . $formattedPrice . '. Transaction ID: "' . $this->paymentIntentId . '".';
                $historyItem->setIsCustomerNotified(0); // @TODO check this (is notified? should we notify?)
            }
        }

        $historyItem->setStatus(Order::STATE_PENDING_PAYMENT);
        $historyItem->setComment($commentContentModified);
        $historyItem->save();
    }

    /**
     *
     */
    private function checkPaymentIntentOnOrder()
    {
        if ($this->beforeOrder) {
            return false;
        }

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
        if ($this->beforeOrder) {
            return;
        }

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();
        // preserve already existing additional data
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
        /** This is not imperative to be used. It works even without it (?) */
        $storeId = $this->storeManager->getStore()->getId();
        $configPath = 'payment/' . $this->paymentMethodCode . '/' . $configKey;

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

            if ($this->beforeOrder) {
                return $this->error($this->getResponseError($transaction));
            } else {
                return $this->redirectToErrorPage($this->getResponseError($transaction));
            }
        }

        return $transaction;
    }

    /**
	 * Checks if the transaction was successful and
	 * the data was not tempered with.
     * 
     * @return bool
     */
    private function isTransactionSuccessful($transaction)
    {
        // if we don't have the order, we only check the successful status.
        if (!$this->order) {
            return true == $transaction['authorisationCreated'];
        }
        // // we need to overwrite the amount in the case of a subscription.
        // if (!$amount) {
        //     $amount = $this->order->getBaseGrandTotal();
        // }

        $matchCurrency = $this->order->getOrderCurrencyCode() == $transaction['amount']['currency'];
        $matchAmount = $this->args['amount'] == $transaction['amount']['decimal'];

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
                $error[] = $fieldError['field'] . ':' . $fieldError['message'];
            }
        }

        return implode(' ', $error);
    }

}
