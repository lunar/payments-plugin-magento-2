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

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Lunar;

/**
 * Manage polling for unpaid orders
 * 
 * @api
 */
class CheckUnpaidOrder
{
    private $storeManager;
    private $logger;
    private $scopeConfig;
    private $orderRepository;
    private $jsonFactory;
    private $requestInterface;
    private $redirectFactory;
    private $response;
    private $messageManager;

    private $invoiceCollectionFactory;
    private $invoiceService;
    private $transactionFactory;
    private $invoiceSender;
    private $priceCurrencyInterface;

    private Lunar $lunarApiClient;
    private string $transactionId = '';
    private $intentIdKey = '_lunar_intent_id';

    private bool $isInstantMode = false;
    private $quotePayment = null;
    private ?Order $order = null;
    private array $args = [];
    private string $paymentIntentId = '';
    private string $controllerURL = 'lunar/index/HostedCheckout';
    private string $paymentMethodCode = '';
    private bool $testMode = false;
    private string $publicKey = '';


    public function __construct(
        ConfigProvider $configProvider,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        JsonFactory $jsonFactory,
        RequestInterface $requestInterface,
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
        PriceCurrencyInterface $priceCurrencyInterface
    ) {
        
        $this->storeManager           = $storeManager;
        $this->logger                 = $logger;
        $this->scopeConfig            = $scopeConfig;
        $this->orderRepository        = $orderRepository;
        $this->jsonFactory            = $jsonFactory;
        $this->requestInterface       = $requestInterface;
        $this->redirectFactory        = $redirectFactory;
        $this->response               = $response;
        $this->messageManager         = $messageManager;

        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender = $invoiceSender;
        $this->priceCurrencyInterface = $priceCurrencyInterface;


        $orderId = $this->requestInterface->getParam('order_id');
        $this->order = $this->orderRepository->get($orderId);

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $cartRepositoryInterface->get($this->order->getQuoteId());
        $this->quotePayment = $quote->getPayment();
        $this->paymentMethodCode = $this->order->getPayment()->getMethod();

        $this->isInstantMode = (CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'));

        $this->testMode = 'test' == $this->getStoreConfigValue('transaction_mode');
        if ($this->testMode) {
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
        if (!$this->order) {
            return;
        }

        if (!in_array($this->paymentMethodCode, [ConfigProvider::LUNAR_PAYMENT_HOSTED_CODE,ConfigProvider::MOBILEPAY_HOSTED_CODE])) {
            return;
        }

        $paymentIntentId = $this->getPaymentIntentFromOrder();


        try {

            if (! $this->paymentIntentId) {
                $errorMessage = 'An error occured creating payment for order. Please try again or contact system administrator.'; // <a href="/">Go to homepage</a>'
                return $this->sendJsonResponse(['error' => $errorMessage], 400);
            }

            $transaction = $this->lunarApiClient->payments()->fetch($paymentIntentId);

            $result = $this->parseApiTransactionResponse($transaction);

            if (! $result) {
                return;
            }

            $this->transactionId = $transaction['id'];

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

            $this->order->save();

        
        } catch(\Exception $e) {
            $this->logger->debug($e->getMessage());
        }

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
        }
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
            return $additionalInformation[$this->intentIdKey];
        }

        return false;
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
        return $this->jsonFactory->create()->setHttpResponseCode($code)->setData($response);
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
        $matchCurrency = $this->order->getOrderCurrencyCode() == $transaction['amount']['currency'];
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
                $error[] = $fieldError['field'] . ':' . $fieldError['message'];
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

}
