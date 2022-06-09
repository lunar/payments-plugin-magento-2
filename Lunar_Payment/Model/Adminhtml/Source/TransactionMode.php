<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class TransactionMode
 */
class TransactionMode implements ArrayInterface
{
    const MODE_LIVE = 'live';
    const MODE_TEST = 'test';

    /**
     * Possible transaction mode types
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::MODE_LIVE,
                'label' => __('Live')
            ],
            [
                'value' => self::MODE_TEST,
                'label' => __('Test'),
            ],
        ];
    }
}
