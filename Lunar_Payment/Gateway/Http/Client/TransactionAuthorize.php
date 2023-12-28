<?php

namespace Lunar\Payment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;

class TransactionAuthorize implements ClientInterface
{
    private const SUCCESS = 1;
    private const FAILURE = 0;

    /**
     * @var array
     */
    private $results = [
        self::SUCCESS,
        self::FAILURE
    ];

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param Logger $logger
     */
    public function __construct(
        Logger $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * Places request to gateway. Returns result as ENV array
     *
     * @param  TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $response = $this->generateResponseForCode($transferObject);

        $this->logger->debug(
            [
                'request' => $transferObject->getBody(),
                'response' => $response
            ]
        );

        return $response;
    }

    /**
     * Generates response
     *
     * @return array
     */
    protected function generateResponseForCode(TransferInterface $transfer)
    {
        $data = $transfer->getBody();

        if (isset($data['TXN_ID'])) {

            $resultCode = self::SUCCESS;
            return array_merge(
                [
                    'RESULT_CODE' => $resultCode,
                    'TXN_ID' => $data['TXN_ID'],
                    'TXN_TYPE' => $data['TXN_TYPE']
                ],
                $this->getFieldsBasedOnResponseType($resultCode)
            );
        }
    }

    /**
     * Returns response fields for result code
     *
     * @param  int $resultCode
     * @return array
     */
    private function getFieldsBasedOnResponseType($resultCode)
    {
        switch ($resultCode) {
        case self::FAILURE:
            return [
                    'FRAUD_MSG_LIST' => [
                        'Stolen card',
                        'Customer location differs'
                    ]
                ];
        }

        return [];
    }
}
