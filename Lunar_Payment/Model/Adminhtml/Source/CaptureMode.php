<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class CaptureMode
 */
class CaptureMode implements ArrayInterface
{
    const MODE_INSTANT = 'instant';
    const MODE_DELAYED = 'delayed';

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
