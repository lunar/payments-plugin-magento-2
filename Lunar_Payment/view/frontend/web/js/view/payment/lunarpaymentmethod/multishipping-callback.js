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

            var checkoutConfig = window.checkoutConfig;
            var lunarConfig = checkoutConfig.lunarpaymentmethod;

            /** Initialize object. */
            var sdkClient = Paylike({key: lunarConfig.publicapikey});

            var paymentConfig = lunarConfig.config;
            var checkoutQuoteData = checkoutConfig.quoteData;
            var checkoutCustomerData = checkoutConfig.customerData;

            var multiplier = lunarConfig.multiplier;
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

            LunarLogger.setContext(paymentConfig, Jquery, MageUrl);

            LunarLogger.log("Opening payment popup (multishipping)");
            
            delete paymentConfig.paymentMethod;

            sdkClient.pay(paymentConfig, callback);
        };

    }
);