<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Lunar\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Checkout\Model\Cart;
use Magento\Framework\View\Asset\Repository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote;

use Lunar\Payment\Model\Adminhtml\Source\AcceptedCards;
use Lunar\Payment\Helper\Data as Helper;
use Lunar\Payment\Gateway\Http\Client\TransactionAuthorize;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface {
	const PLUGIN_CODE = 'lunarpaymentmethod';
	const PLUGIN_VERSION = '1.1.0';
	protected $scopeConfig;
	protected $_cart;
	protected $_assetRepo;
	protected $_storeManager;
	protected $locale;
	protected $cards;
	protected $helper;

	public function __construct(
		ScopeConfigInterface $scopeConfig,
		Cart $cart,
		Repository $assetRepo,
		CartRepositoryInterface $cartRepositoryInterface,
		StoreManagerInterface $storeManager,
		Resolver $locale,
		AcceptedCards $cards,
		Helper $helper
	) {
		$this->scopeConfig             = $scopeConfig;
		$this->_cart                   = $cart;
		$this->_assetRepo              = $assetRepo;
		$this->cartRepositoryInterface = $cartRepositoryInterface;
		$this->_storeManager           = $storeManager;
		$this->locale                  = $locale;
		$this->cards                   = $cards;
		$this->helper                  = $helper;
	}

	/**
	 * Retrieve assoc array of checkout configuration
	 *
	 * @return array
	 */
	public function getConfig() {
		return [
			'payment'      => [
				self::PLUGIN_CODE => [
					'transactionResults' => [
						TransactionAuthorize::SUCCESS => __( 'Success' ),
						TransactionAuthorize::FAILURE => __( 'Fraud' )
					]
				]
			],
			'description'  => $this->getDescription(),
			'config'       => $this->getConfigJSON(),
			'publicapikey' => $this->getPublicApiKey(),
			'cards'        => $this->getAcceptedCards(),
			'url'          => $this->getImageUrl(),
			'multiplier'   => $this->getMultiplier( $this->getStoreCurrentCurrency() )
		];
	}

	/**
	 * Retrieve description from backend
	 *
	 * @return string
	 */

	public function getDescription() {
		return $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/description', ScopeInterface::SCOPE_STORE );
	}

	/**
	 * Retrieve URLs of selected credit cards from backend
	 *
	 * @return array
	 */

	public function getImageUrl() {
		$acceptedcards = $this->getAcceptedCards();
		$selectedcards = explode( ",", $acceptedcards );

		$finalcards = array();
		$key        = 0;
		foreach ( $selectedcards as $value ) {
			$finalcards[ $key ] = $this->_assetRepo->getUrl( 'Lunar_Payment::images/paymenticons/' . $value . '.svg' );
			$key                = $key + 1;
		}

		return $finalcards;
	}

	/**
	 * Get quote object associated with cart. By default it is current customer session quote
	 *
	 * @return Quote
	 */

	protected function _getQuote() {
		return $this->_cart->getQuote();
	}

	/**
	 * Retrieve title of backup from backend
	 *
	 * @return string
	 */

	public function getPopupTitle() {
		$title = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/popup_title', ScopeInterface::SCOPE_STORE );
		if ( ! $title ) {

			$title =$this->_storeManager->getStore()->getName();
		}

		return $title;
	}

	public function getLogsEnabled() {
		$enabled = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/enable_logs', ScopeInterface::SCOPE_STORE );

		return $enabled === "1";
	}

	/**
	 * Retrieve accepted credit cards from backend
	 *
	 * @return string
	 */

	public function getAcceptedCards() {
		return $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/acceptedcards', ScopeInterface::SCOPE_STORE );
	}

	/**
	 * Retrieve public API key according to the mode selected from backend
	 *
	 * @return string
	 */

	public function getPublicApiKey() {
		$mode = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/transaction_mode', ScopeInterface::SCOPE_STORE );
		$key  = "";
		if ( $mode == "test" ) {
			$key = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/test_api_key', ScopeInterface::SCOPE_STORE );
		} else if ( $mode == "live" ) {
			$key = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/live_api_key', ScopeInterface::SCOPE_STORE );
		}

		return $key;
	}

	/**
	 * Retrieve current store currency
	 *
	 * @return string
	 */

	public function getStoreCurrentCurrency() {
		return $this->_storeManager->getStore()->getCurrentCurrency()->getCode();

	}

	/**
	 * Get multiplier for currency
	 *
	 * @param string $currency Accepted currency.
	 *
	 * @return float|int
	 */

	public function getMultiplier( $currency ) {
		return $this->helper->getCurrencyMultiplier( $currency );

	}

	public function getAmount( $currency,$amount ) {
		return $this->helper->getAmount( $currency,$amount );

	}

	public function getExponent( $currency ) {
		return $this->helper->getCurrency( $currency )['exponent'];

	}

	/**
	 * Retrieve config values for popup of payment method
	 *
	 * @return string
	 */

	public function getConfigJSON() {
		$test_mode  = $this->scopeConfig->getValue( 'payment/lunarpaymentmethod/transaction_mode', ScopeInterface::SCOPE_STORE );
		$quote    	= $this->_getQuote();
		$title    	= $this->getPopupTitle();
		$currency 	= $this->getStoreCurrentCurrency();
		$total      = $quote->getGrandTotal();

		$amount = $this->getAmount($currency,$total);

		$exponent = $this->getExponent($currency);

		$email    = $quote->getBillingAddress()->getEmail();
		$products = array();
		foreach ( $quote->getAllVisibleItems() as $item ) {
			$product    = array(
				'ID'       => $item->getProductId(),
				'SKU'      => $item->getSku(),
				'name'     => $item->getName(),
				'quantity' => $item->getQty()
			);
			$products[] = $product;
		}

		$quoteId 	  = $quote->getId();
		$quote        = $this->cartRepositoryInterface->get( $quote->getId() );
		$customerData = $quote->getCustomer();
		$address      = $quote->getBillingAddress();
		$name         = $customerData->getFirstName() . " " . $customerData->getLastName();
		$logsEnabled  = $this->getLogsEnabled();

		$objectManager = ObjectManager::getInstance();
		$ip            = $objectManager->get( 'Magento\Framework\HTTP\PhpEnvironment\RemoteAddress' );

		$customer = array(
			'name'    => $name,
			'email'   => $email,
			'phoneNo' => $address->getTelephone(),
			'address' => "",
			'IP'      => $ip->getRemoteAddress()
		);

		$productMetadata = $objectManager->get( 'Magento\Framework\App\ProductMetadataInterface' );
		$magentoVersion  = $productMetadata->getVersion();

		$platform = array(
			'name'    => 'Magento',
			'version' => $magentoVersion
		);


		$version = self::PLUGIN_VERSION;

		return [
			'test'    		=> $test_mode,
			'title'    		=> $title,
			'amount'   		=> [
				'currency' => $currency,
				'exponent' => $exponent,
				'value'    => $amount,
			],
			'locale'   		=> $this->locale->getLocale(),
			'custom'   		=> [
				'quoteId' 		=> $quoteId,
				'products'  	=> $products,
				'customer'  	=> $customer,
				'platform'  	=> $platform,
				'pluginVersion' => $version,
				'logsEnabled'   => $logsEnabled,
			]
		];
	}
}
