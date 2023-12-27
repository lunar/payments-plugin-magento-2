<?php

namespace Lunar\Payment\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Multishipping\Model\Checkout\Type\Multishipping;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\State;

/**
 * Lunar Multishipping checkout success controller.
 */
class MultishippingSuccess extends \Magento\Multishipping\Controller\Checkout\Success
{
    /** @var State */
    private $state;

    /** @var Multishipping */
    private $multishipping;

    public function __construct(
        Context $context,
        State $state,
        Multishipping $multishipping
    ) {
        $this->state = $state;
        $this->multishipping = $multishipping;

        parent::__construct($context, $state, $multishipping);
    }

    public function execute()
    {
        $this->_view->loadLayout('multishipping_checkout_success');
        $ids = $this->multishipping->getOrderIds();
        $this->_eventManager->dispatch('multishipping_checkout_controller_success_action', ['order_ids' => $ids]);
        $this->_view->renderLayout();
    }
}
