<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class CheckoutMode
 */
class CheckoutMode implements OptionSourceInterface
{
    private const BEFORE_ORDER = 'before_order';
    private const AFTER_ORDER = 'after_order';

    /**
     * Possible checkout mode types
     *
     * @return array
     */

    public function toOptionArray()
    {
        return [
            [
                'value' => self::BEFORE_ORDER,
                'label' => __('Payment before order created'),
            ],
            [
                'value' => self::AFTER_ORDER,
                'label' => __('Redirect to payment page after order created'),
            ]
        ];
    }
}
