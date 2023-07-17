define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/customer-email-validator',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/url'
    ],
    function (
                Jquery,
                PaymentDefaultComponent,
                Quote,
                CustomerEmailValidator,
                RedirectOnSuccessAction,
                MageUrl
            ) {

        'use strict';

        return PaymentDefaultComponent.extend({
            defaults: {
                template: 'Lunar_Payment/payment/lunarpaymenthosted',
                transactionid: '',
                lunarHostedConfig: window.checkoutConfig.lunarpaymenthosted,
                logger: window.LunarLoggerHosted
            },

            showPopup: function () {
                if (!CustomerEmailValidator.validate()) {
                    return false;
                }

                var self = this;

                /** Initialize object. */
                var sdkClient = Paylike({key: this.lunarHostedConfig.publicapikey});

                var paymentConfig = this.lunarHostedConfig.config;
                var grandTotal = parseFloat(Quote.totals()['grand_total']);
                var taxAmount = parseFloat(Quote.totals()['tax_amount']);
                var totalAmount = grandTotal + taxAmount;
                paymentConfig.amount.value = Math.round(totalAmount * this.lunarHostedConfig.multiplier);

                /** Change test key value from string 'test' with a boolean value. */
                paymentConfig.test = ('test' === paymentConfig.test) ? (true) : (false);

                if (Quote.guestEmail) {
                    paymentConfig.custom.customer.name = Quote.billingAddress()['firstname'] + " " + Quote.billingAddress()['lastname'];
                    paymentConfig.custom.customer.email = Quote.guestEmail;
                }

                paymentConfig.custom.customer.phoneNo = Quote.billingAddress().telephone;
                paymentConfig.custom.customer.address = Quote.billingAddress().street[0] + ", " + Quote.billingAddress().city + ", " + Quote.billingAddress().region + " " + Quote.billingAddress().postcode + ", " + Quote.billingAddress().countryId;

                self.logger.setContext(paymentConfig, Jquery, MageUrl);

                self.logger.log("Opening payment popup");

                sdkClient.pay(paymentConfig, function (err, res) {
                    if (err) {
                        if(err === "closed") {
                            self.logger.log("Popup closed by user");
                        }
                        /**
                         * (Need improvement/rethink the logic)
                         * In "test" mode if user closes the popup, we need to refresh the page.
                         * Otherwise, the popup will initialize in live mode.
                         */
                         if ('test' === paymentConfig.test) {
                            return location.reload();
                        }

                        return console.warn(err);
                    }

                    if (res.transaction.id !== undefined && res.transaction.id !== "") {
                        self.transactionid = res.transaction.id;
                        self.logger.log("Payment successfull. Transaction ID: " + res.transaction.id);
                        /*
                          In order to intercept the error of placeOrder request we need to monkey-patch
                          the `addErrorMessage` function of the messageContainer:
                           - first we duplicate the function on the same `messageContainer`, keeping the same `this`
                           - next we override the function with a new one, were we log the error, and then we call the old function
                        */
                        self.messageContainer.oldAddErrorMessage = self.messageContainer.addErrorMessage;
                        self.messageContainer.addErrorMessage = async function (messageObj) {
                          await self.logger.log("Place order failed. Reason: " + messageObj.message);

                          self.messageContainer.oldAddErrorMessage(messageObj);
                        }

                        /*
                          In order to log the placeOrder success, we need deactivate
                          the redirect after order placed and call it manually, after
                          we send the logs to the server
                        */
                        self.redirectAfterPlaceOrder = false;
                        self.afterPlaceOrder = async function (args) {
                          await self.logger.log("Order placed successfully");
                          RedirectOnSuccessAction.execute();
                        }

                        /* Everything is now setup, we can try placing the order */
                        self.placeOrder();
                    }

                    else {
                        self.logger.log("No transaction id returned from gateway, order not placed");

                        return false;
                    }
                });
            },

            /** Returns send check to info */
            getMailingAddress: function () {
                return window.checkoutConfig.payment.checkmo.mailingAddress;
            },

            getDescription: function () {
                return this.lunarHostedConfig.description;
            },

            getCardLogos: function () {
                var logosString = this.lunarHostedConfig.cards;

                if (!logosString) {
                    return '';
                }

                var logos = logosString.split(',');
                var imghtml = "";
                if (logos.length > 0) {
                    for (var i = 0; i < logos.length; i++) {
                        imghtml = imghtml + "<img src='" + this.lunarHostedConfig.url[i] + "' alt='" + logos[i] + "' width='45'>";
                    }
                }

                return imghtml;
            },
            
            getTitle: function () {
                return this.lunarHostedConfig.methodTitle;
            },

            getData: function () {
                return {
                    "method": this.getCode(),
                    'additional_data': {
                        'transactionid': this.transactionid
                    }
                };
            },

        });
    }
);
