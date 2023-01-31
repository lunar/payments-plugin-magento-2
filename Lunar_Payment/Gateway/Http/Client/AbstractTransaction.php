<?php


namespace Lunar\Payment\Gateway\Http\Client;

use Lunar\Payment\Model\Adapter\PaymentAdapter;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Lunar\Payment\Helper\Data as Helper;


/**
 * Class AbstractTransaction
 */
abstract class AbstractTransaction implements ClientInterface
{
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
	public function placeRequest( TransferInterface $transferObject ) {
		$value = $transferObject->getBody();
		$response['object'] = [];

		$amount = $this->helper->getAmount( $value['CURRENCY'], $value['AMOUNT'] );
		$data = array(
			'amount'   => $amount,
			'currency' => $value['CURRENCY']
		);
		$response['object'] = [];

		try {
			$response['object'] = $this->process( $value['TXN_ID'], $data );
		} catch ( \Exception $e ) {
			$message = __( $e->getMessage() ?: 'Sorry, but something went wrong' );
			$this->logger->critical( $message );
			throw new ClientException( $message );
		} finally {
			if ( $response['object'] == false ) {
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
	 * @return Paylike response
	 */
	abstract protected function process( $transactionid, array $data );
}
