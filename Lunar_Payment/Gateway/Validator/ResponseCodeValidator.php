<?php

namespace Lunar\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Lunar\Payment\Gateway\Http\Client\TransactionAuthorize;

class ResponseCodeValidator extends AbstractValidator
{
    private const RESULT_CODE = 'RESULT_CODE';

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        if (!isset($validationSubject['response']) || !is_array($validationSubject['response'])) {
            throw new \InvalidArgumentException(__('Response does not exist'));
        }

        $response = $validationSubject['response'];

        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            return $this->createResult(
                false,
                [__('Gateway rejected the transaction.')]
            );
        }
    }

    /**
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        return isset($response[self::RESULT_CODE])
            && $response[self::RESULT_CODE] !== TransactionAuthorize::FAILURE;
    }
}
