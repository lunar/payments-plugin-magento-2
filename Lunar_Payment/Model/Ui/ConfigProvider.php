<?php

namespace Lunar\Payment\Model\Ui;

use Magento\Checkout\Model\Session;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\ProductMetadataInterface;

use Lunar\Payment\Helper\Data as Helper;
use Lunar\Payment\Gateway\Http\Client\TransactionAuthorize;

/**
 *
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const LUNAR_PAYMENT_CODE = 'lunarpaymentmethod';
    public const MOBILEPAY_CODE = 'lunarmobilepay';

    public const LUNAR_PAYMENT_HOSTED_CODE = 'lunarpaymenthosted';
    public const MOBILEPAY_HOSTED_CODE = 'lunarmobilepayhosted';

    public const LUNAR_HOSTED_METHODS = [
        self::LUNAR_PAYMENT_HOSTED_CODE,
        self::MOBILEPAY_HOSTED_CODE,
    ];

    private $scopeConfig;
    private $_checkoutSession;
    private $_assetRepo;
    private $cartRepositoryInterface;
    private $_storeManager;
    private $helper;
    private $isQuote;
    private $fileDriver;
    private $remoteAddress;
    private $productMetadata;

    private $paymentMethods = [];

    private $order = null;
    private ?string $paymentMethodCode = '';
    private ?string $transactionMode = '';


    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Session $checkoutSession,
        Repository $assetRepo,
        CartRepositoryInterface $cartRepositoryInterface,
        StoreManagerInterface $storeManager,
        Helper $helper,
        File $fileDriver,
        RemoteAddress $remoteAddress,
        ProductMetadataInterface $productMetadata,
        array $paymentMethods
    ) {
        $this->scopeConfig             = $scopeConfig;
        $this->_checkoutSession        = $checkoutSession;
        $this->_assetRepo              = $assetRepo;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->_storeManager           = $storeManager;
        $this->helper                  = $helper;
        $this->fileDriver              = $fileDriver;
        $this->remoteAddress           = $remoteAddress;
        $this->productMetadata         = $productMetadata;
        $this->paymentMethods          = $paymentMethods;
    }

    /**
     *
     */
    public function setOrder($order, $isQuote = false)
    {
        $this->order = $order;
        $this->isQuote = $isQuote;
        return $this;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $configData = [];

        foreach ($this->paymentMethods as $methodCode) {

            $this->paymentMethodCode = $methodCode;
            $this->transactionMode = $this->getStoreConfigValue('transaction_mode');

            /**
             * Send config data only when a payment method is active and properly set 
             */
            if (
                $this->getStoreConfigValue('active')
                &&
                (
                    ('live' === $this->transactionMode
                        && $this->getStoreConfigValue('live_app_key')
                    )
                    ||
                    ('test' === $this->transactionMode
                        && $this->getStoreConfigValue('test_app_key')
                    )
                    || $this->getStoreConfigValue('app_key')
                )
            ) {
                $configData['payment'][$methodCode]['transactionResults'] = [
                    TransactionAuthorize::SUCCESS => __('Success'),
                    TransactionAuthorize::FAILURE => __('Fraud'),
                ];

                $configData[$methodCode] = [
                    'checkoutMode' => $this->getStoreConfigValue('checkout_mode'),
                    'description'  => $this->getStoreConfigValue('description'),
                    'config'       => $this->getConfigJSON(),
                    'publicapikey' => $this->getPublicKey(),
                    'cards'        => $this->getAcceptedCards(),
                    'url'          => $this->getImageUrl(),
                    'multiplier'   => $this->getMultiplier($this->getStoreCurrentCurrency()),
                    'logsEnabled'  => $this->getLogsEnabled(),
                    'methodTitle'  => $this->getStoreConfigValue('title'),
                ];
            }
        }

        return $configData;
    }

    /**
     * Retrieve URLs of selected credit cards from backend
     *
     * @return array
     */
    private function getImageUrl()
    {

        if (in_array($this->paymentMethodCode, [self::MOBILEPAY_CODE, self::MOBILEPAY_HOSTED_CODE])) {
            return [$this->_assetRepo->getUrl('Lunar_Payment::images/paymenticons/mobilepay-logo.png')];
        }

        $acceptedCards = $this->getAcceptedCards();
        $selectedCards = explode(",", $acceptedCards);

        $finalCards = array();
        foreach ($selectedCards as $value) {
            $finalCards[] = $this->_assetRepo->getUrl('Lunar_Payment::images/paymenticons/' . $value . '.svg');
        }

        return $finalCards;
    }

    /**
     * Get quote object associated with cart. By default it is current customer session quote
     */
    private function _getQuote()
    {
        if ($this->order && $this->isQuote) {
            return $this->cartRepositoryInterface->get($this->order->getId());
        } else if ($this->order) {
            return $this->cartRepositoryInterface->get($this->order->getQuoteId());
        }

        return $this->_checkoutSession->getQuote();
    }

    /**
     * Retrieve title of backup from backend
     *
     * @return string
     */
    private function getPopupTitle()
    {
        return $this->getStoreConfigValue('popup_title') ?: $this->getStoreName();
    }

    /**
     *
     */
    private function getStoreName()
    {
        return $this->_storeManager->getStore()->getName();
    }

    private function getLogsEnabled()
    {
        return "1" === $this->getStoreConfigValue('enable_logs');
    }

    /**
     * Retrieve accepted credit cards from backend
     *
     * @return string
     */
    private function getAcceptedCards()
    {
        return $this->getStoreConfigValue('acceptedcards') ?? '';
    }

    /**
     * Retrieve public API key according to the mode selected from backend
     *
     * @return string
     */
    private function getPublicKey()
    {
        return 'test' === $this->transactionMode
            ? $this->getStoreConfigValue('test_public_key')
            : $this->getStoreConfigValue('live_public_key');
    }

    /**
     * Retrieve current store currency
     *
     * @return string
     */
    private function getStoreCurrentCurrency()
    {
        return $this->_storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    /**
     * Get multiplier for currency
     *
     * @param string $currency Accepted currency.
     *
     * @return float|int
     */
    private function getMultiplier($currency)
    {
        return $this->helper->getCurrencyMultiplier($currency);
    }

    private function getAmount($currency, $amount)
    {
        return $this->helper->getAmount($currency, $amount);
    }

    private function getExponent($currency)
    {
        return $this->helper->getCurrency($currency)['exponent'];
    }


    /**
     * Retrieve config values for popup of payment method
     *
     * @return string
     */
    private function getConfigJSON()
    {

        /** @var Quote @quote */
        $quote = $this->_getQuote();

        $title = $this->getPopupTitle();
        $currency = $this->getStoreCurrentCurrency();
        $total = $quote->getGrandTotal();

        $amount = $this->getAmount($currency, $total);
        $exponent = $this->getExponent($currency);


        $products = array();
        foreach ($quote->getAllVisibleItems() as $item) {
            $product    = array(
                'ID'       => $item->getProductId(),
                'SKU'      => $item->getSku(),
                'name'     => $item->getName(),
                'quantity' => $item->getQty(),
                'Unit Price'  => number_format($item->getPriceInclTax() ?? 0.0, $exponent),
            );

            if ($item->getQty() > 1) {
                $product['Total Price'] = number_format($item->getRowTotalInclTax() ?? 0.0, $exponent);
            }

            $products[] = $product;
        }

        $customerData = $quote->getCustomer();
        $email        = $quote->getBillingAddress()->getEmail();
        $name         = $customerData->getFirstName() . " " . $customerData->getLastName();
        $address      = $quote->getBillingAddress();

        if (!$email) {
            $email = $quote->getCustomerEmail();
        }
        if (!$name) {
            $name = $quote->getCustomerName();
        }

        if (!$address) {
            $address = $quote->getShippingAddress();
        }

        $customerAddress = $address->getStreet()[0] . ", "
            . $address->getCity() . ", "
            . $address->getRegion() . " "
            . $address->getPostcode() . ", "
            . $address->getCountryId();

        $customer = array(
            'name'    => $name,
            'email'   => $email,
            'phoneNo' => $address->getTelephone(),
            'address' => $customerAddress,
            'IP'      => $this->remoteAddress->getRemoteAddress()
        );

        $args = [
            'test' => $this->transactionMode,
            'title' => $title,
            'amount' => [
                'currency' => $currency,
                'exponent' => $exponent,
                'value'    => $amount,
                /**
                 * @TODO re-check this in another scenarios, or get separators dynamically
                 * see also in AbstractTransaction class
                 */
                'decimal'  => number_format($total, $exponent, '.', ''), // remove thousands separator
            ],
            'custom' => [
                'quoteId' => $quote->getId(),
                'products'     => $products,
                'shipping tax' => number_format($quote->getShippingAddress()->getShippingInclTax() ?? 0.0, $exponent),
                'customer'     => $customer,
                'platform' => [
                    'name'    => 'Magento',
                    'version' => $this->productMetadata->getVersion()
                ],
                'lunarPluginVersion' => json_decode($this->fileDriver->fileGetContents(dirname(__DIR__, 2) . '/composer.json'))->version,
            ],
        ];

        /**
         * Unset some unnecessary args for hosted request
         *
         * @TODO remove them when hosted migration will be done
         */
        if (in_array($this->paymentMethodCode, self::LUNAR_HOSTED_METHODS)) {
            unset(
                $args['test'],
                $args['title'],
                $args['amount']['value'],
                $args['amount']['exponent'],
            );
        }


        return $args;
    }

    /**
     * Get store config value
     *
     * @param string $configId
     */
    private function getStoreConfigValue($configKey)
    {
        return $this->scopeConfig->getValue(
            'payment/' . $this->paymentMethodCode . '/' . $configKey,
            ScopeInterface::SCOPE_STORE,
            $this->_storeManager->getStore()->getId()
        );
    }
}
