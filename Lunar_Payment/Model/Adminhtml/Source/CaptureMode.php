<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 *
 */
class CaptureMode implements OptionSourceInterface
{
    public const MODE_INSTANT = 'instant';
    public const MODE_DELAYED = 'delayed';

    /**
     * Possible capture mode types
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::MODE_INSTANT,
                'label' => __('Instant'),
            ],
            [
                'value' => self::MODE_DELAYED,
                'label' => __('Delayed'),
            ]
        ];
    }
}
