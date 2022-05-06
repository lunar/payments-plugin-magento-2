/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'mage/url'
    ],
    function (
            Jquery,
            Quote,
            Customer,
            MageUrl
            ) {
        'use strict';

        return function displayPopup (callback) {
            /** Store window payment configuration. */
            var checkoutConfig = window.checkoutConfig;

            /** Initialize object. */
            var sdkClient = Paylike({key: checkoutConfig.publicapikey});

            var paymentConfig = checkoutConfig.config;
            var checkoutQuoteData = checkoutConfig.quoteData;
            var checkoutCustomerData = checkoutConfig.customerData;

            var multiplier = checkoutConfig.multiplier;
            var grandTotal = parseFloat(checkoutQuoteData.grand_total);

            paymentConfig.amount.value = Math.round(grandTotal * multiplier);

            /** Change test key value from string 'test' with a boolean value. */
            paymentConfig.test = ('test' === paymentConfig.test) ? (true) : (false);

            Quote.guestEmail = checkoutConfig.customerData.email;
            /** Need to be logged in to perform checkout with multishipping. */
            Customer.setIsLoggedIn(checkoutConfig.isCustomerLoggedIn);

            var customerAddresses = checkoutCustomerData.addresses;

            /** Get billing address from customer addresses. */
            var billingAddress = '';

            for (var key in customerAddresses){
                if (customerAddresses[key].default_billing) {
                    var billingAddress = customerAddresses[key];
                }
            }

            if (!Customer.isLoggedIn) {
                paymentConfig.custom.customer.name = billingAddress.firstname + " " + billingAddress.lastname;
                paymentConfig.custom.customer.email = quote.guestEmail;
            }

            paymentConfig.custom.customer.phoneNo = billingAddress.telephone;
            paymentConfig.custom.customer.address = billingAddress.street[0] + ", " +
                                                    billingAddress.city + ", " +
                                                    billingAddress.region.region + " " +
                                                    billingAddress.postcode + ", " +
                                                    billingAddress.country_id;

            PluginLogger.setContext(paymentConfig, Jquery, MageUrl);

            PluginLogger.log("Opening payment popup");

            sdkClient.pay(paymentConfig, callback);
        };

    }
);