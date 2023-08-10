<?php

namespace Lunar\Payment\lib\Lunar;

include_once( 'ApiAdapter.php' );
include_once( 'LunarHostedApiAdapter.php' );
include_once( 'Transaction.php' );
include_once( 'Card.php' );

/**
 * Class Client
 * @package Lunar
 * Manages the app creation.
 */
if ( ! class_exists( 'Lunar\\Client' ) ) {
    class Client {

        /**
         * @var
         * This is the adapter, similar to a db engine,
         * it can be changed with any class that has its capabilities,
         * which are making requests to api. In the future the adapter
         * will be extended from an interface.
         */
        private static $adapter = null;

        /**
         * @param $privateApiKey
         * Set the api key for future calls
         */
        public static function setKey( $privateApiKey, $paymentMethodCode = '' ) {
            // self::$adapter = new ApiAdapter( $privateApiKey );
            self::setAdapter($privateApiKey, $paymentMethodCode);
        }

        /**
         * @param null $privateApiKey
         * Returns the object that will be responsible for making the calls to the api
         *
         * @return bool|null|ApiAdapter|LunarHostedApiAdapter
         */
        public static function getAdapter( $privateApiKey = null, $paymentMethodCode = '' ) {
            if ( self::$adapter ) {
                return self::$adapter;
            } else {
                if ( $privateApiKey ) {
                    // return new ApiAdapter( $privateApiKey );
                    self::setAdapter($privateApiKey, $paymentMethodCode);
                } else {
                    return false;
                }
            }
        }

        /** 
         * 
         */
        private static function setAdapter($privateApiKey, $paymentMethodCode)
        {
            if ($paymentMethodCode != null && str_contains($paymentMethodCode, 'hosted')) {
                self::$adapter = new LunarHostedApiAdapter($privateApiKey);
            } else {
                self::$adapter = new ApiAdapter($privateApiKey);
            }
        }

    }
}
