<?php

namespace Lunar\Payment\Cron;

use Psr\Log\LoggerInterface;

use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

use Lunar\Lunar;
use Lunar\Exception\ApiException;
use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CaptureMode;
use Lunar\Payment\Setup\Patch\Data\AddNewOrderStatusPatch;

/**
 * Cron responsible for checking unpaid orders to see if they are authorized 
 * even if the customer did't return to the website to finalize the transaction 
 */
class LunarCheckUnpaidOrdersCron
{
    private const TEST_MODE = false;
 
    private $logger;
    private $request;
    private $scopeConfig;
    private $orderRepository;
    private $orderCollectionFactory;
    
    /** @var Order $order */
    private $order = null;
    private $transactionId = '';
    private $paymentMethodCode = '';
    private $apiClient;


    public function __construct(
        LoggerInterface $logger,
        RequestInterface $request,
        ScopeConfigInterface $scopeConfig,
        OrderRepository $orderRepository,
        OrderCollectionFactory $orderCollectionFactory
    ) {
        $this->logger                      = $logger;
        $this->request                     = $request;
        $this->scopeConfig                 = $scopeConfig;
        $this->orderRepository             = $orderRepository;
        $this->orderCollectionFactory      = $orderCollectionFactory;
    }


    public function execute()
    {
        $timestamp = time();
        $to = date('Y-m-d H:i:s', $timestamp);
        $aDayAgo = $timestamp - 86400;
        $from = date('Y-m-d H:i:s', $aDayAgo);

        $latestOrders = $this->orderCollectionFactory->create()
            ->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'state',
                ['in' => [Order::STATE_NEW]]
            )->addFieldToFilter(
                'status',
                ['eq' => 'pending']
            )->addFieldToFilter(
                'created_at',
                [
                    'from' => $from,
                    'to' => $to,
                    'datetime' => true,
                ]
            );

        $latestOrders
            ->getSelect()
            ->joinLeft(
                ['payment' => 'sales_order_payment'],
                'payment.parent_id = main_table.entity_id',
                ['payment_method' => 'payment.method']
            )->where(
                'payment.method IN (?)', ConfigProvider::LUNAR_HOSTED_METHODS
            );

        if ($latestOrders->count()) {
            $this->logger->debug('"Start Lunar polling for (' 
                . $latestOrders->count() . ') orders between" ' . $from . ' - ' . $to);
        }

        /** @TODO delegate order processing logic */

        foreach ($latestOrders as $this->order) {

            $this->writeLog('polling', date('Y-m-d H:i:s'));

            $this->paymentMethodCode = $this->order->getPayment()->getMethod();

            $this->apiClient = new Lunar($this->getStoreConfigValue('app_key'), null, self::TEST_MODE);

            $this->transactionId = $this->getPaymentIntent();

            if (empty($this->transactionId)) {
                $this->writeLog('no transaction ID found');
                /** We don't want to fetch this order next time */
                $this->order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                $this->orderRepository->save($this->order);
                continue;
            }

            try {
                $apiResponse = $this->apiClient->payments()->fetch($this->transactionId);
            } catch (ApiException $e) {
                $this->writeLog('API Exception', $e->getMessage(), true);
            }

            if (empty($apiResponse)) {
                $this->writeLog('no transaction returned by the API', 'transaction id: '. $this->transactionId);
                continue;
            }

            if (isset($apiResponse['authorisationCreated']) && $apiResponse['authorisationCreated'] === true) {
                $this->finalizeOrder();
            }
        }
    }

    /**
     *
     */
    private function getPaymentIntent()
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $orderPayment = $this->order->getPayment();
        $additionalInformation = $orderPayment->getAdditionalInformation();

        if ($additionalInformation && array_key_exists('transactionid', $additionalInformation)) {
            return $additionalInformation['transactionid'];
        }

        return null;
    }

    /**
     * 
     */
    private function finalizeOrder()
    {
        $this->request->setParams([
            'order_id' => $this->order->getId(),
            'lunar_testmode' => self::TEST_MODE, // testing purpose only
        ]);

        try {
            /** @var \Magento\Sales\Model\Order\Payment $orderPayment */
            $orderPayment = $this->order->getPayment();

            $orderPayment->setTransactionId($this->transactionId);
            $orderPayment->setQuotePaymentId($this->order->getQuote()?->getPayment()?->getId());
            $orderPayment->setAmountAuthorized($this->order->getGrandTotal());

            $orderPayment->authorize($isOnline = true, $this->order->getBaseGrandTotal());

            if ((CaptureMode::MODE_INSTANT == $this->getStoreConfigValue('capture_mode'))) {
                
                $orderPayment->capture();

            } else {
                $this->order->setState(Order::STATE_PROCESSING)
                            ->setStatus(AddNewOrderStatusPatch::ORDER_STATUS_PAYMENT_RECEIVED_CODE);
            }
            
            $this->orderRepository->save($this->order);

            $this->writeLog('success');

        } catch (\Exception $e) {
            $this->writeLog('updating order payment', $e->getMessage(), true);
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
            $this->order->getStoreId()
        );
    }

    /**
     *
     */
    private function writeLog($beginText, $finalText = '', $isException = false)
    {
        $exceptionMark = $isException ? '(Exception)' : '';

        $this->logger->debug(
            "\"Lunar polling {$exceptionMark}: {$beginText} for order - \" "
            . $this->order->getId() . ' -- "' . $finalText . '"'
        );
    }
}
