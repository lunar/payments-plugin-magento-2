<?php

namespace Lunar\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class DataAssignObserver extends AbstractDataAssignObserver
{
    private const TRANSACTION_RESULT = 'transactionid';

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);
        if (isset($additionalData[self::TRANSACTION_RESULT])) {
            $paymentInfo->setAdditionalInformation(
                self::TRANSACTION_RESULT,
                $additionalData[self::TRANSACTION_RESULT]
            );
        }
    }
}
