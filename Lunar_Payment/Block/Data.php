<?php

namespace Lunar\Payment\Block;


class Data  extends \Magento\Framework\View\Element\Template
{
    protected $_checkoutSession;
    protected $configProvider;
    protected $serializer;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\CompositeConfigProvider $configProvider,
        \Magento\Framework\Serialize\Serializer\Json $serializer,
        array $data = []
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->configProvider = $configProvider;
        $this->serializer = $serializer;

        parent::__construct($context, $data);
    }

    /**
     * Retrieve code of current payment method
     *
     * @return mixed
     */
    public function getSelectedMethodCode()
    {
        $method = $this->getQuote()->getPayment()->getMethod();
        if ($method) {
            return $method;
        }
        return false;
    }

    /**
     * Retrieve quote model object
     *
     * @return \Magento\Quote\Model\Quote
     */
    public function getQuote()
    {
        return $this->_checkoutSession->getQuote();
    }

    /**
     * Returns serialized checkout config.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function getSerializedCheckoutConfigs(): string
    {
        return $this->serializer->serialize($this->configProvider->getConfig());
    }
}