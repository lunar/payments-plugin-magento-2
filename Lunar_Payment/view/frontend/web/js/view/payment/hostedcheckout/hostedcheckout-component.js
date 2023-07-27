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
                transactionid: '',
				paymentButtonSelector: '.action.primary.checkout',
                redirectUrl: window.checkoutConfig.defaultSuccessPageUrl,
                controllerURL: "lunar/index/HostedCheckout",
                logger: window.LunarLoggerHosted,
            },

            redirectToPayment: function () {
                if (!CustomerEmailValidator.validate()) {
                    return false;
                }

                let self = this;

                let paymentConfig = this.checkoutConfig.config;
                paymentConfig.test = 'test' === paymentConfig.test;

                let isMobilePay = 'lunarmobilepayhosted' === paymentConfig.paymentMethod;
                self.logger.setContext(paymentConfig, Jquery, MageUrl, isMobilePay);


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
                            args: paymentConfig,
                        },
                        success: function(data) {
                            // window.location.replace(MageUrl.build(self.controllerURL + '?order_id=' + data.order_id));
                            window.location.replace(data.paymentRedirectURL);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            self.submitError('<div class="lunarmobilepay-error">' + errorThrown + '</div>');
                        }
                    });
                }

                self.placeOrder();
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
                return this.checkoutConfig.description;
            },

            getTitle: function () {
                return this.checkoutConfig.methodTitle;
            },

            getLogo: function () {
                var logoUrl = this.checkoutConfig.url[0];
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

            getCardLogos: function () {
                var logosString = this.checkoutConfig.cards;

                if (!logosString) {
                    return '';
                }

                var logos = logosString.split(',');
                var imghtml = "";
                if (logos.length > 0) {
                    for (var i = 0; i < logos.length; i++) {
                        imghtml = imghtml + "<img src='" + this.checkoutConfig.url[i] + "' alt='" + logos[i] + "' width='45'>";
                    }
                }

                return imghtml;
            },
        });
    }
);