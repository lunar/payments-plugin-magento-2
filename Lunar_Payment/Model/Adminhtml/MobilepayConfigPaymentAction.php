<?php

namespace Lunar\Payment\Model\Adminhtml;

use Magento\Framework\App\Config\Value;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\RequestInterface;

use Lunar\Payment\Model\Ui\ConfigProvider;
use Lunar\Payment\Model\Adminhtml\Source\CheckoutMode;

/**
 * Class MobilepayConfigPaymentAction
 *
 * Change payment_action to prevent inserting payment transaction
 * before an order is authorized in after_order payment flow.
 */
class MobilepayConfigPaymentAction extends Value
{
    private const MOBILEPAY_HOSTED_CODE = ConfigProvider::MOBILEPAY_HOSTED_CODE;

    public function __construct(
        ConfigInterface $configInterface,
        RequestInterface $request,
        Context $context,
        Registry $registry,
        ScopeConfigInterface $scopeConfigInterface,
        TypeListInterface $cacheTypeList,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configInterface = $configInterface;

        $websiteId = $request->getParam('website') ?? null;
        $storeId   = $request->getParam('store') ?? null;

        $this->configScope = $websiteId 
            ? ScopeInterface::SCOPE_WEBSITE 
            : ($storeId ? ScopeInterface::SCOPE_STORE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $this->entityId = $websiteId ?? $storeId ?? 0; // set the default if the request did not come for either the website or the store

        $this->paymentActionPath = 'payment/' . self::MOBILEPAY_HOSTED_CODE . '/payment_action';
        // $this->checkoutModePath = 'payment/' . self::MOBILEPAY_HOSTED_CODE . '/checkout_mode';

        parent::__construct(
            $context, 
            $registry, 
            $scopeConfigInterface, 
            $cacheTypeList, 
            $resource, 
            $resourceCollection, 
            $data
        );
    }

    /**
     * In the website or store context (in admin)
     * this method is triggered only when the "Use default" checkbox is not checked (before was checked)
     */
    public function beforeSave()
    {
        $checkoutMode = $this->getValue();

        /** In before_order flow we delete the value. The default value will be used (authorize). */
        if (CheckoutMode::BEFORE_ORDER == $checkoutMode) {
            $this->configInterface->deleteConfig($this->paymentActionPath, $this->configScope, $this->entityId);
        } elseif (CheckoutMode::AFTER_ORDER == $checkoutMode) {
            $this->configInterface->saveConfig($this->paymentActionPath, null, $this->configScope, $this->entityId);
        }

        return $this;
    }

    /**
     * In the website or store context (in admin)
     * this method is triggered only when the "Use default" checkbox is checked (before was unchecked)
     */
    public function beforeDelete()
    {
        /** Delete payment_action config for all checkout modes. The default value will be used. */
        $this->configInterface->deleteConfig($this->paymentActionPath, $this->configScope, $this->entityId);

        return $this;
    }
}
