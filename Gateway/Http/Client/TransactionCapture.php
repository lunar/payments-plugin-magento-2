<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Lunar\Payment\Gateway\Http\Client;

class TransactionCapture extends AbstractTransaction
{
    /**
     * Process http request
     * @param string $transactionId
     * @param array $data
     * @return Paylike response
     */
    protected function process($transactionid, array $data)
    {
        return $this->adapter->capture(
            $transactionid,
            $data
        );
    }
}
