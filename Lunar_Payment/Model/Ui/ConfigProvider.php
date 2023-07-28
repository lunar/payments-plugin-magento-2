<?php

namespace Lunar\Payment\Model\Ui;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Locale\Resolver;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Asset\Repository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

use Lunar\Payment\Helper\Data as Helper;
use Lunar\Payment\Model\Adminhtml\Source\AcceptedCards;
use Lunar\Payment\Gateway\Http\Client\TransactionAuthorize;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
	const PLUGIN_VERSION = '1.4.0';

	const LUNAR_PAYMENT_CODE = 'lunarpaymentmethod';
	const MOBILEPAY_CODE = 'lunarmobilepay';
	
	const LUNAR_PAYMENT_HOSTED_CODE = 'lunarpaymenthosted';
	const MOBILEPAY_HOSTED_CODE = 'lunarmobilepayhosted';

	private $scopeConfig;
	private $_cart;
	private $_assetRepo;
	private $cartRepositoryInterface;
	private $_storeManager;
	private $locale;
	private $cards;
	private $helper;

	private $paymentMethods = [];

	private $order = null;
	private ?string $paymentMethodCode = '';
	private ?string $transactionMode = '';


	public function __construct(
		ScopeConfigInterface $scopeConfig,
		Cart $cart,
		Repository $assetRepo,
		CartRepositoryInterface $cartRepositoryInterface,
		StoreManagerInterface $storeManager,
		Resolver $locale,
		AcceptedCards $cards,
		Helper $helper,
		array $paymentMethods
	) {
		$this->scopeConfig             = $scopeConfig;
		$this->_cart                   = $cart;
		$this->_assetRepo              = $assetRepo;
		$this->cartRepositoryInterface = $cartRepositoryInterface;
		$this->_storeManager           = $storeManager;
		$this->locale                  = $locale;
		$this->cards                   = $cards;
		$this->helper                  = $helper;
		$this->paymentMethods		   = $paymentMethods;
	}

	/**
	 *
	 */
	public function setOrder($order)
	{
		$this->order = $order;
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

			/** Send config data only when a payment method is active. */
			if ($this->getStoreConfigValue('active')) {
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

	private function getImageUrl() {

		if (in_array($this->paymentMethodCode, [self::MOBILEPAY_CODE, self::MOBILEPAY_HOSTED_CODE])) {
			return [$this->_assetRepo->getUrl('Lunar_Payment::images/paymenticons/mobilepay-logo.png' )];
		}

		$acceptedCards = $this->getAcceptedCards();
		$selectedCards = explode(",", $acceptedCards );

		$finalCards = array();
		foreach ($selectedCards as $value) {
			$finalCards[] = $this->_assetRepo->getUrl('Lunar_Payment::images/paymenticons/' . $value . '.svg' );
		}

		return $finalCards;
	}

	/**
	 * Get quote object associated with cart. By default it is current customer session quote
	 */
	private function _getQuote() {
		if ($this->order) {
			return $this->cartRepositoryInterface->get($this->order->getQuoteId());
		}

		return $this->_cart->getQuote();
	}

	/**
	 * Retrieve title of backup from backend
	 *
	 * @return string
	 */

	private function getPopupTitle() {
		return $this->getStoreConfigValue('popup_title') ?: $this->getStoreName();
	}

	/**
	 * 
	 */
	private function getStoreName()
	{
		return $this->_storeManager->getStore()->getName();
	}

	private function getLogsEnabled() {
		return "1" === $this->getStoreConfigValue('enable_logs');
	}

	/**
	 * Retrieve accepted credit cards from backend
	 *
	 * @return string
	 */

	private function getAcceptedCards() {
		return $this->getStoreConfigValue('acceptedcards') ?? '';
	}

	/**
	 * Retrieve public API key according to the mode selected from backend
	 *
	 * @return string
	 */

	private function getPublicKey() {
		return 'test' === $this->transactionMode
				? $this->getStoreConfigValue('test_public_key')
				: $this->getStoreConfigValue('live_public_key');
	}

	/**
	 * Retrieve current store currency
	 *
	 * @return string
	 */

	private function getStoreCurrentCurrency() {
		return $this->_storeManager->getStore()->getCurrentCurrency()->getCode();

	}

	/**
	 * Get multiplier for currency
	 *
	 * @param string $currency Accepted currency.
	 *
	 * @return float|int
	 */

	private function getMultiplier($currency) {
		return $this->helper->getCurrencyMultiplier($currency);

	}

	private function getAmount($currency,$amount) {
		return $this->helper->getAmount($currency, $amount);

	}

	private function getExponent($currency) {
		return $this->helper->getCurrency($currency)['exponent'];

	}


	/**
	 * Retrieve config values for popup of payment method
	 *
	 * @return string
	 */
	private function getConfigJSON() {

		/** @var Quote @quote */
		$quote    	= $this->_getQuote();

		$title    	= $this->getPopupTitle();
		$currency 	= $this->getStoreCurrentCurrency();
		$total      = $quote->getGrandTotal();

		$amount = $this->getAmount($currency,$total);
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

		// if (! $this->order) {
		// 	$quoteId = $quote->getId();
		// 	$quote   = $this->cartRepositoryInterface->get($quote->getId());
		// }

		$customerData = $quote->getCustomer();
		$email        = $quote->getBillingAddress()->getEmail();
		$name         = $customerData->getFirstName() . " " . $customerData->getLastName();
		$address      = $quote->getBillingAddress();

        if (! $email) { $email = $quote->getCustomerEmail(); }
        if (! $name) { $name = $quote->getCustomerName(); }

        if (! $address) {
            $address = $quote->getShippingAddress();
        }

        $customerAddress = $address->getStreet()[0] . ", "
							. $address->getCity() . ", "
							. $address->getRegion() . " "
							. $address->getPostcode() . ", "
							. $address->getCountryId();

		$objectManager = ObjectManager::getInstance();
		$ip            = $objectManager->get('Magento\Framework\HTTP\PhpEnvironment\RemoteAddress');

		$customer = array(
			'name'    => $name,
			'email'   => $email,
			'phoneNo' => $address->getTelephone(),
			'address' => $customerAddress,
			'IP'      => $ip->getRemoteAddress()
		);

		$productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
		$magentoVersion  = $productMetadata->getVersion();

		return [
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
			'locale' => $this->locale->getLocale(),
			'custom' => [
				'quoteId' => $quote->getId(),
				'products'     => $products,
                'shipping tax' => number_format($quote->getShippingAddress()->getShippingInclTax() ?? 0.0, $exponent),
				'customer'     => $customer,
				'ecommerce' => [
					'name'    => 'Magento',
					'version' => $magentoVersion
				],
				'lunarPluginVersion' => self::PLUGIN_VERSION,
				'paymentMethod'  => $this->paymentMethodCode,
			],
		];
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
