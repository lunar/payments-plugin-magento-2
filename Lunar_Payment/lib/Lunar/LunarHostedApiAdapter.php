<?php
namespace Lunar\Payment\lib\Lunar;

use Lunar\Lunar;

/**
 * Class LunarHostedApiAdapter
 * @package Lunar
 * The adapter class taking care of the calls to the api.
 *
 * The purpose of this is to abstract the requests
 * so that this can be changed depending on the environment.
 *
 */
if (!class_exists('Lunar\\LunarHostedApiAdapter')) {
    class LunarHostedApiAdapter
    {

        private $apiKey;

        /**
         * ApiAdapter constructor.
         *
         * @param $privateApiKey
         */
        public function __construct($privateApiKey)
        {
            if ($privateApiKey) {
                $this->apiKey = $privateApiKey;
            } else {
                trigger_error('Private Key is missing!', E_USER_ERROR);

                return null;
            }
        }

        /**
         * Place request via Lunar php sdk
         * The $url param is kept for compatibility with lib\Lunar\Transaction class
         */
        public function request($url, $data = null)
        {
            $lunarApiClient = new Lunar($this->apiKey);

            $data = $data['lunarHosted']; // set in AbstractTransation class

            return match (true) {
                str_contains($url, 'capture') => $lunarApiClient->payments()->capture($data['id'], $data),
                str_contains($url, 'refund') => $lunarApiClient->payments()->refund($data['id'], $data),
                str_contains($url, 'void') => $lunarApiClient->payments()->cancel($data['id'], $data)
            };
        }
    }
}
