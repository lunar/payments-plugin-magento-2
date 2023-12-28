<?php

namespace Lunar\Payment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;

use Lunar\Payment\Helper\Data as Helper;
use Lunar\Payment\Model\Adapter\PaymentAdapter;


/**
 *
 */
abstract class AbstractTransaction implements ClientInterface
{
    protected $logger;
    protected $adapter;
    protected $helper;

    public function __construct(
        Logger $logger,
        PaymentAdapter $adapter,
        Helper $helper
    ) {
        $this->logger = $logger;
        $this->adapter = $adapter;
        $this->helper = $helper;
    }

    /**
     * @inheritdoc
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $value = $transferObject->getBody();
        $response['object'] = [];

        $amount = $this->helper->getAmount($value['CURRENCY'], $value['AMOUNT']);
        $decimals = $this->helper->getCurrency($value['CURRENCY'])['exponent'] ?? 2;
        $data = [
        'amount'   => $amount,
        'currency' => $value['CURRENCY'],
        'lunarHosted' => [
        'amount' => [
        'currency' => $value['CURRENCY'],
        'decimal' => number_format($value['AMOUNT'], $decimals, '.', ''),
        ],
        'id' => $value['TXN_ID'],
        ],
        ];

        try {
            $response['object'] = $this->process($value['TXN_ID'], $data);
        } catch (\Exception $e) {
            $message = __($e->getMessage() ?: 'Sorry, but something went wrong');
            $this->logger->debug((array) $message);
            throw new ClientException($message);
        } finally {
            if ($response['object'] == false || isset($response['object']['declinedReason'])) {
                $response['RESULT_CODE'] = 0;
            } else {
                $response['RESULT_CODE'] = 1;
            }

            $response['TXN_ID'] = $value['TXN_ID'];
            $response['TXN_TYPE'] = $value['TXN_TYPE'];

            $this->logger->debug(
                [
                'request'  => $data,
                'response' => $response
                ]
            );
        }

        return $response;
    }

    /**
     * Process http request
     *
     * @param string $transactionId
     * @param array  $data
     *
     * @return Paylike|Lunar response
     */
    abstract protected function process($transactionid, array $data);
}
