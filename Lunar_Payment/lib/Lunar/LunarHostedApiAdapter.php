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
         * @TODO adjust this method when the hosted transition will be completed
         */
        public function request($url, $data = null)
        {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $cookieManager = $objectManager->get('\Magento\Framework\Stdlib\CookieManagerInterface');

            $testMode = !!$cookieManager->getCookie('lunar_testmode');

            $lunarApiClient = new Lunar($this->apiKey, null, $testMode);

            $data = $data['lunarHosted']; // set in AbstractTransation class

            switch (true) {
                case str_contains($url, 'capture'):
                    return $lunarApiClient->payments()->capture($data['id'], $data);
                case str_contains($url, 'refund'):
                    return $lunarApiClient->payments()->refund($data['id'], $data);
                case str_contains($url, 'void'):
                    return $lunarApiClient->payments()->cancel($data['id'], $data);
            }
        }
    }
}
