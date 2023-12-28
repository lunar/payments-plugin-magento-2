<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 *
 */
class AcceptedCards implements OptionSourceInterface
{
    private const CARD_VISA = 'visa';
    private const CARD_VISAELECTRON = 'visaelectron';
    private const CARD_MASTERCARD = 'mastercard';
    private const CARD_MAESTRO = 'maestro';


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
