<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class MobilePayConfigId
 */
class MobilePayConfigId extends Value
{
    /**
     * Method used for checking if the new value is valid before saving.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $configurationId = $this->getValue();

        if (strlen($configurationId) != 32) {
            /** Mark the new value as invalid */
            $this->_dataSaveAllowed = false;
            throw new LocalizedException(
                __('The Mobile Pay config id key doesn\'t seem to be valid. It should have exactly 32 characters. Current count: ' . strlen($configurationId))
            );
        }

        return $this;
    }
}
