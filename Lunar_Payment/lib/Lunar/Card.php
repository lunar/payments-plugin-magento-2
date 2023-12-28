<?php

namespace Lunar\Payment\lib\Lunar;

/**
 * Class Card
 *
 * @package Lunar
 * Handles card operations.
 */
if (!class_exists('Lunar\\Card')) {
    class Card
    {

        /**
         * Fetches information about a card
         *
         * @param $cardId
         *
         * @return int|mixed
         */
        public static function fetch($cardId)
        {
            $adapter = Client::getAdapter();
            if (!$adapter) {
                trigger_error('ApiAdapter not set!', E_USER_ERROR);
            }

            return $adapter->request('cards/' . $cardId, $data = null, $httpVerb = 'get');
        }
    }
}
