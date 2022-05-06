<?php
namespace Lunar\Payment\Model\Adminhtml\Source;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ManageField extends Field {

  const VENDOR_NAME = 'lunar';
  protected $_template = 'Lunar_Payment::system/config/ManageField.phtml';

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
}
