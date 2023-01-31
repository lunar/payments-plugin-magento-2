<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class MobilePayLogoUrl
 */
class MobilePayLogoUrl extends Value
{
    /**
     * Method used for checking if the new value is valid before saving.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $logoUrl = $this->getValue();
        $allowedExtensions = ['png', 'jpg', 'jpeg'];

        /** Check if the new value is empty. Even if we validate in frontend */
        if ( ! $logoUrl) {
			return $this;
        }

        try {
            $fileSpecs = getimagesize($logoUrl);
        } catch (\Exception $e) {
            throw new LocalizedException(__('The image file doesn\'t seem to be valid'));
        }

        $fileMimeType = explode('/', $fileSpecs['mime'] ?? '');
        $fileExtension = end($fileMimeType);

        // $fileDimensions = ($fileSpecs[0] ?? '') . 'x' . ($fileSpecs[1] ?? '');
        // strcmp('250x250', $fileDimensions) !== 0      // disabled for the moment

        if (! preg_match('/^https:\/\//', $logoUrl)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;
			throw new LocalizedException(__('The image url must begin with https://.'));
		}

        if (! in_array($fileExtension, $allowedExtensions)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;
			throw new LocalizedException(__('The image file must have one of the following extensions: ' . implode(', ', $allowedExtensions)));
		}

        return $this;
    }
}
