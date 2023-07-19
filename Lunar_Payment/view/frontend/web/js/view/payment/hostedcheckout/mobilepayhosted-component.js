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
                MageUrl,
           ) {

        'use strict';

        return PaymentDefaultComponent.extend({
            defaults: {
                template: 'Lunar_Payment/payment/lunarmobilepayhosted',
                transactionid: '',
                publicApiKey: '',
                mobilePayConfig: window.checkoutConfig.lunarmobilepayhosted,
                lastIframeId: 0,
                iframeChallenges: [],
                hints: [],
                isSubmitPermitted: false,
                beforeOrder: true,
				paymentButtonSelector: '.action.primary.checkout',
                redirectUrl: window.checkoutConfig.defaultSuccessPageUrl,
                controllerURL: "lunar/index/HostedCheckout",
                logger: window.LunarLoggerHosted,
            },

            /** @inheritdoc */
            initialize: function () {
                this._super();

                this.publicApiKey = this.mobilePayConfig.publicapikey;

                if ('after_order' == this.mobilePayConfig.checkoutMode) {
                    this.beforeOrder = false;
                }

                return this;
            },

            makePayment: function () {
                if (!CustomerEmailValidator.validate()) {
                    return false;
                }

                var self = this;

                var paymentConfig = this.mobilePayConfig.config;
                var grandTotal = parseFloat(Quote.totals()['grand_total']);
                var taxAmount = parseFloat(Quote.totals()['tax_amount']);
                var totalAmount = grandTotal + taxAmount;
                paymentConfig.amount.value = Math.round(totalAmount * this.mobilePayConfig.multiplier);

                /** Change test key value from string 'test' with a boolean value. */
                paymentConfig.test = ('test' === paymentConfig.test) ? (true) : (false);
                paymentConfig.checkoutMode = this.mobilePayConfig.checkoutMode;

                if (Quote.guestEmail) {
                    paymentConfig.custom.customer.name = Quote.billingAddress()['firstname'] + " " + Quote.billingAddress()['lastname'];
                    paymentConfig.custom.customer.email = Quote.guestEmail;
                }

                paymentConfig.custom.customer.phoneNo = Quote.billingAddress().telephone;
                paymentConfig.custom.customer.address = Quote.billingAddress().street[0] + ", " 
                                                        + Quote.billingAddress().city + ", " 
                                                        + Quote.billingAddress().region + " " 
                                                        + Quote.billingAddress().postcode + ", " 
                                                        + Quote.billingAddress().countryId;

                let isMobilePay = true;
                self.logger.setContext(paymentConfig, Jquery, MageUrl, isMobilePay);


                /** AFTER order flow. */
                if (!self.beforeOrder){
                    self.redirectAfterPlaceOrder = false;

                    /**
                     * Workaround to save order transaction when authorize
                     * Will be replaced from controller when get called on redirect
                     */
                    self.transactionid = 'trxid_placeholder';


                    /** Change default behavior after order. */
                    self.afterPlaceOrder = async function () {
                        await Jquery.ajax({
                            type: "POST",
                            dataType: "json",
                            url: "/" + self.controllerURL,
                            data: {
                                payment_intent: true,
                            },
                            success: function(data) {
                                /** Replace default success url with call to our controller */
                                // window.location.replace(MageUrl.build(self.controllerURL + '?order_id=' + data.order_id));
                                window.location.replace(data.payment_intent_url);
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                self.submitError('<div class="lunarmobilepay-error">' + errorThrown + '</div>');
                            }
                        });
                    }


                    self.logger.log("Payment mode: " + paymentConfig.checkoutMode);

                    self.placeOrder();

                }
                /** BEFORE order flow. */
                else {
                    self.logger.log("Payment mode: " + paymentConfig.checkoutMode);
                    
                    this.initiatePaymentServerCall(paymentConfig, function(response) {

                        if(response.error){
                            self.logger.log("Error occured: " + response.error);

                            self.submitError(response.error);
                            return false;
                        }

                        if (response.data.authorizationId !== undefined && response.data.authorizationId !== "") {
                            self.transactionid = response.data.authorizationId;
                            self.logger.log("Payment successfull. Authorization ID: " + response.data.authorizationId);
                            /*
                                * In order to intercept the error of placeOrder request we need to monkey-patch
                                * the `addErrorMessage` function of the messageContainer:
                                * - first we duplicate the function on the same `messageContainer`, keeping the same `this`
                                * - next we override the function with a new one, were we log the error, and then we call the old function
                                */
                            self.messageContainer.oldAddErrorMessage = self.messageContainer.addErrorMessage;
                            self.messageContainer.addErrorMessage = async function (messageObj) {
                                await self.logger.log("Place order failed. Reason: " + messageObj.message);

                                self.messageContainer.oldAddErrorMessage(messageObj);
                            }

                            /*
                                * In order to log the placeOrder success, we need deactivate
                                * the redirect after order placed and call it manually, after
                                * we send the logs to the server
                                */
                            self.redirectAfterPlaceOrder = false;
                            self.afterPlaceOrder = async function () {
                                await self.logger.log("Order placed successfully");
                                RedirectOnSuccessAction.execute();
                            }

                            /* Everything is now setup, we can try placing the order */
                            self.placeOrder();
                        }

                        // // self.disablePaymentButton();

                        // if (response.data.type === 'redirect') {
                        //     window.location.href = response.data.url;
                        //     return false;
                        // }
                    });
                }
            },

            initiatePaymentServerCall: function(args, successCallback) {
                var self = this;

                args.hints = self.hints;

                Jquery.ajax({
                    type: "POST",
                    dataType: "json",
                    url: "/" + self.controllerURL,
                    data: {
                        args: args,
                    },
                    success: function(data) {
                        successCallback(data)
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        self.logger.log("Error occurred on server call: " + errorThrown);

                        self.submitError('<div class="lunarmobilepay-error">' + errorThrown + '</div>');
                    },
                    always: function() {
                        //
                    }
                });
            },

            submitError: function(errorMessage) {
				Jquery('#lunarmobilepayhosted_messages').prepend(errorMessage).show()
            },

            disablePaymentButton: function() {
                Jquery(this.paymentButtonSelector).prop('disabled', true);
            },

            enablePaymentButton: function() {
                Jquery(this.paymentButtonSelector).prop('disabled', false);
            },

            
            /** Returns send check to info */
            getMailingAddress: function () {
                return window.checkoutConfig.payment.checkmo.mailingAddress;
            },

            getDescription: function () {
                return this.mobilePayConfig.description;
            },

            getTitle: function () {
                return this.mobilePayConfig.methodTitle;
            },

            getLogo: function () {
                var logoUrl = this.mobilePayConfig.url[0];
                if (!logoUrl) return '';

                return "  <img src='" + logoUrl + "' alt='mobilepay logo' style='height:5rem'>";
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
