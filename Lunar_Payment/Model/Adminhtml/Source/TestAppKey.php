<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

use Paylike\Paylike as ApiClient;
use Paylike\Exception\ApiException;
use Lunar\Payment\Helper\Data as Helper;

/**
 * Class TestAppKey
 */
class TestAppKey extends Value
{
    /**
	 * @var Helper
	 */
	protected $helper;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param Helper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        Helper $helper,
        array $data = []
    ) {
        $this->helper = $helper;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Method used for checking if the new value is valid before saving.
     *
     * @return $this
     */
    public function beforeSave()
    {
        /** Check if the new value is empty. */
        if ( ! $this->getValue() ) {
			return $this;
        }

        /** Create an Api client. */
        $api_client = new ApiClient( $this->getValue() );

        /** Validate the test app key by extracting the identity of the api client. */
        try {
			$identity = $api_client->apps()->fetch();
		} catch ( ApiException $exception ) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;

            $message = __( "The test private key doesn't seem to be valid." );
            $message = $this->helper->handle_exceptions( $exception, $message );
			throw new LocalizedException( $message );
        }

        /** Extract and save all the test public keys of the merchants with the above extracted identity. */
        try {
			$merchants = $api_client->merchants()->find( $identity['id'] );
			if ( $merchants ) {
				foreach ( $merchants as $merchant ) {
					if ( $merchant['test'] ) {
						Helper::$validation_test_public_keys[] = $merchant['key'];
					}
				}
			}
		} catch ( ApiException $exception ) {
			// we handle in the following statement
        }

        if ( empty( Helper::$validation_test_public_keys ) ) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;

			$message = __( "The test private key is not valid or set to live mode." );
			throw new LocalizedException( $message );
		}

        return $this;
    }
}
