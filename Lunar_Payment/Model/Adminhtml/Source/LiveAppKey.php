<?php

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
 *
 */
class LiveAppKey extends Value
{
    /**
     * @var Helper
     */
    protected $helper;


    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Helper $helper,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
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
        if (!$this->getValue()) {
            return $this;
        }

        $api_exception = null;
        /** Instantiate Api client. */
        $api_client = new ApiClient($this->getValue());

        /** Validate the live app key by extracting the identity of the client. */
        try {
            $identity = $api_client->apps()->fetch();
        } catch (ApiException $exception) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;

            $message = __("The live private key doesn't seem to be valid.");
            $message = $this->helper->handle_exceptions($exception, $message);
            throw new LocalizedException($message);
        }

        /** Extract and save all the live public keys of the merchants with the above extracted identity. */
        try {
            $merchants = $api_client->merchants()->find($identity['id']);
            if ($merchants) {
                foreach ($merchants as $merchant) {
                    if (!$merchant['test']) {
                        Helper::$validation_live_public_keys[] = $merchant['key'];
                    }
                }
            }
        } catch (ApiException $exception) {
            // we handle in the following statement
            $api_exception = $exception;
        }

        if (empty(Helper::$validation_live_public_keys)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;

            $message = __("The live private key is not valid or set to test mode.");
            if ($api_exception) {
                $message = $this->helper->handle_exceptions($api_exception, $message);
            }
            throw new LocalizedException($message);
        }

        return $this;
    }
}
