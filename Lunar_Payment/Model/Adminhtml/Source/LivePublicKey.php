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

use Lunar\Payment\Helper\Data as Helper;

/**
 *
 */
class LivePublicKey extends Value
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

        /** Check if we have saved any validation live public keys. */
        if (empty(Helper::$validation_live_public_keys)) {
            return $this;
        }

        /** Check if the public key is exists among the saved ones. */
        if (!in_array($this->getValue(), Helper::$validation_live_public_keys)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;

            $message = __("The live public key doesn't seem to be valid.");
            throw new LocalizedException($message);
        }

        return $this;
    }
}
