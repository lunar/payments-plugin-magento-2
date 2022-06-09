<?php
namespace Lunar\Payment\lib\Lunar;
include_once( 'ApiAdapter.php' );
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
        public static function setKey( $privateApiKey ) {
            self::$adapter = new ApiAdapter( $privateApiKey );
        }

        /**
         * @param null $privateApiKey
         * Returns the object that will be responsible for making the calls to the api
         *
         * @return bool|null|ApiAdapter
         */
        public static function getAdapter( $privateApiKey = null ) {
            if ( self::$adapter ) {
                return self::$adapter;
            } else {
                if ( $privateApiKey ) {
                    return new ApiAdapter( $privateApiKey );
                } else {
                    return false;
                }
            }
        }

    }
}
