<?php
namespace Lunar\Payment\lib\Lunar;
/**
 * Class ApiAdapter
 * @package Lunar
 * The adapter class taking care of the calls to the api.
 *
 * The purpose of this is to abstract the requests
 * so that this can be changed depending on the environment.
 *
 */
if (!class_exists('Lunar\\ApiAdapter')) {
    class ApiAdapter
    {
        use LunarApiAdapterTrait;

        private $apiUrl = 'https://api.paylike.io';
    }
}
