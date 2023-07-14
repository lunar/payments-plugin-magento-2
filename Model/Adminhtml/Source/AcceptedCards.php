<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class AcceptedCards
 */
class AcceptedCards implements OptionSourceInterface
{
    const CARD_VISA = 'visa';
    const CARD_VISAELECTRON = 'visaelectron';
    const CARD_MASTERCARD = 'mastercard';
    const CARD_MAESTRO = 'maestro';


    /**
     * Possible credit card types
     *
     * @return array
     */

    public function toOptionArray()
    {
        return [
            [
                'value' => self::CARD_VISA,
                'label' => __('Visa'),
            ],
            [
                'value' => self::CARD_VISAELECTRON,
                'label' => __('Visa Electron'),
            ],
            [
                'value' => self::CARD_MASTERCARD,
                'label' => __('MasterCard'),
            ],
            [
                'value' => self::CARD_MAESTRO,
                'label' => __('Maestro'),
            ],
        ];
    }
}
