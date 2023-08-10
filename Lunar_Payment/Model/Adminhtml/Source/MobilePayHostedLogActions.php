<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class MobilePayHostedLogActions
 */
class MobilePayHostedLogActions extends Field
{

  const VENDOR_NAME = 'lunarmobilepayhosted';
  protected $_template = 'Lunar_Payment::system/config/MobilePayHostedLogActions.phtml';

  public function __construct(Context $context, array $data = []) {
    parent::__construct($context, $data);
  }

  public function render(AbstractElement $element) {
    $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
    return parent::render($element);
  }

  /**
   * This param is needed for compatibility with
   * Magento\Config\Block\System\Config\Form\Field::_getElementHtml(AbstractElement $element)
   * @param AbstractElement $element
   */
  protected function _getElementHtml(AbstractElement $element) {
    return $this->_toHtml();
  }

  public function getCustomUrl() {
    return $this->getUrl('router/controller/action');
  }

  public function getExportButtonHtml() {
    $button = $this->getLayout()
                ->createBlock('Magento\Backend\Block\Widget\Button')
                ->setData(
                    [
                        'id' => self::VENDOR_NAME . '_logs_export_button', 'label' => __('Export logs')
                    ]
            );

    return $button->toHtml();
  }

  public function getDeleteButtonHtml() {
    $button = $this->getLayout()
                ->createBlock('Magento\Backend\Block\Widget\Button')
                ->setData(
                    [
                        'id' => self::VENDOR_NAME . '_logs_delete_button', 'label' => __('Delete logs')
                    ]
            );

    return $button->toHtml();
  }
}
