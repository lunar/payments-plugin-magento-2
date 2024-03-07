<?php

namespace Lunar\Payment\Plugin;

use Magento\Multishipping\Controller\Checkout\OverviewPost;
use Lunar\Payment\Model\Ui\ConfigProvider;

/**
 * Lunar Multishipping checkout success controller.
 */
class MultishippingRedirect
{
    private $multishipping;
    private $storeManager;
    private $response;

    public function __construct(
        \Magento\Multishipping\Model\Checkout\Type\Multishipping $multishipping,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Response\Http $response
    ) {
        $this->multishipping = $multishipping;
        $this->storeManager = $storeManager;
        $this->response = $response;
    }

    public function aroundExecute(OverviewPost $subject, callable $proceed)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->multishipping->getQuote();

        $proceed();

        if (! in_array($quote->getPayment()->getMethod(), ConfigProvider::LUNAR_HOSTED_METHODS)) {
            return;
        }

        $redirectUrl = $this->storeManager->getStore()->getBaseUrl()
                        . '/lunar/index/LunarRedirect/?multishipping=1&quote_id=' . $quote->getId();

        return $this->response->setRedirect($redirectUrl);
    }
}
