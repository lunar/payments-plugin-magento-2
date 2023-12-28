<?php

namespace Lunar\Payment\Block;

/**
 * @codeCoverageIgnore
 */
class RemoveMethod extends \Magento\Config\Block\System\Config\Form
{
    /**
     * Initialize config field group
     *
     * @param \Magento\Config\Model\Config\Structure\Element\Group $group
     * @param \Magento\Config\Model\Config\Structure\Element\Section $section
     * @param \Magento\Framework\Data\Form\AbstractForm $form
     * @return void
     */
    protected function _initGroup(
        \Magento\Config\Model\Config\Structure\Element\Group $group,
        \Magento\Config\Model\Config\Structure\Element\Section $section,
        \Magento\Framework\Data\Form\AbstractForm $form
    ) {

        $methodCode = $group->getId();
        if ($methodCode === 'lunarpaymentmethod' || $methodCode === 'lunarmobilepay') {
            $active = $this->getConfigValue('payment/' . $methodCode . '/active');

            $appKey = $this->getConfigValue('payment/' . $methodCode . '/live_app_key')
                ?? $this->getConfigValue('payment/' . $methodCode . '/test_app_key')
                ?? $this->getConfigValue('payment/' . $methodCode . '/app_key');

            if (!$active && !$appKey) {
                return;
            }
        }

        parent::_initGroup($group, $section, $form);
    }
}
