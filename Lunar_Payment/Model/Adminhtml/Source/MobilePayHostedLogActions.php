<?php

namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Widget\Button;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 *
 */
class MobilePayHostedLogActions extends Field
{

    private const VENDOR_NAME = 'lunarmobilepayhosted';
    protected $_template = 'Lunar_Payment::system/config/MobilePayHostedLogActions.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * This param is needed for compatibility with
     * Magento\Config\Block\System\Config\Form\Field::_getElementHtml(AbstractElement $element)
     * @param AbstractElement $element
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getCustomUrl()
    {
        return $this->getUrl('router/controller/action');
    }

    public function getExportButtonHtml()
    {
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData(
                [
                    'id' => self::VENDOR_NAME . '_logs_export_button', 'label' => __('Export logs')
                ]
            );

        return $button->toHtml();
    }

    public function getDeleteButtonHtml()
    {
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData(
                [
                    'id' => self::VENDOR_NAME . '_logs_delete_button', 'label' => __('Delete logs')
                ]
            );

        return $button->toHtml();
    }
}
