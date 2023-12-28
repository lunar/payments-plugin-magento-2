<?php

namespace Lunar\Payment\Gateway\Http\Client;

class TransactionRefund extends AbstractTransaction
{
    /**
     * Process http request
     *
     * @param  string $transactionId
     * @param  array  $data
     * @return Paylike|Lunar response
     */
    protected function process($transactionid, array $data)
    {
        return $this->adapter->refund(
            $transactionid,
            $data
        );
    }
}
