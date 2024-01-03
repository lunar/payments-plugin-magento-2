<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class TransactionMode
 */
class TransactionMode implements ArrayInterface
{
    private const MODE_LIVE = 'live';
    private const MODE_TEST = 'test';

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
