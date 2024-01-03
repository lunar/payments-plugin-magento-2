<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 *
 */
class LogoUrl extends Value
{
    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    private $curl;

    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);

        $this->curl = $curl;
    }

    /**
     * Method used for checking if the new value is valid before saving.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $logoUrl = $this->getValue();
        $allowedExtensions = ['png', 'jpg', 'jpeg'];

        if (!preg_match('/^https:\/\//', $logoUrl)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;
            throw new LocalizedException(__('The image url must begin with https://.'));
        }

        if (!$this->fileExists($logoUrl)) {
            $this->_dataSaveAllowed = false;
            throw new LocalizedException(__('The image file doesn\'t seem to be valid'));
        }

        // try {
        //     $fileSpecs = getimagesize($logoUrl); // deprecated, use getimagesizefromstring
        // } catch (\Exception $e) {
        //     $this->_dataSaveAllowed = false;
        //     throw new LocalizedException(__('The image file doesn\'t seem to be valid'));
        // }

        // $fileMimeType = explode('/', $fileSpecs['mime'] ?? '');
        // $fileExtension = end($fileMimeType);

        // // $fileDimensions = ($fileSpecs[0] ?? '') . 'x' . ($fileSpecs[1] ?? '');
        // // strcmp('250x250', $fileDimensions) !== 0      // disabled for the moment

        // if (! in_array($fileExtension, $allowedExtensions)) {
        //     /** Mark the new value as invalid */
        //     $this->_dataSaveAllowed = false;
        //     throw new LocalizedException(__('The image file must have one of the following extensions: ' . implode(', ', $allowedExtensions)));
        // }

        return $this;
    }

    /**
     * @return bool
     */
    private function fileExists($url)
    {
        $this->curl->setOptions([
            CURLOPT_HEADER => 1,
            CURLOPT_NOBODY => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FRESH_CONNECT => 1,
        ]);

        $this->curl->get($url);

        if ($this->curl->getStatus() >= 400) {
            return false;
        }

        return true;
    }
}
