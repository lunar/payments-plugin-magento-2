<?php
namespace Lunar\Payment\lib\Lunar;

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
        use LunarApiAdapterTrait;

        private $apiUrl = 'https://api.prod.lunarway.com/merchant-payments/v1';
    }
}
