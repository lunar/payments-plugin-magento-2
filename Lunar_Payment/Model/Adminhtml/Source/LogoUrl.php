<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class LogoUrl
 */
class LogoUrl extends Value
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

        if (! preg_match('/^https:\/\//', $logoUrl)) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;
			throw new LocalizedException(__('The image url must begin with https://.'));
		}

        if (!$this->fileExists($logoUrl)) {
            $this->_dataSaveAllowed = false;
            throw new LocalizedException(__('The image file doesn\'t seem to be valid'));
        }

        // try {
        //     $fileSpecs = getimagesize($logoUrl);
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
		// 	throw new LocalizedException(__('The image file must have one of the following extensions: ' . implode(', ', $allowedExtensions)));
		// }

        return $this;
    }

    /**
     * @return bool
     */
    private function fileExists($url)
    {
        $valid = true;

        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_HEADER, 1);
        curl_setopt($c, CURLOPT_NOBODY, 1);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_FRESH_CONNECT, 1);
        
        if(!curl_exec($c)){
            $valid = false;
        }

        curl_close($c);

        return $valid;
    }
}
