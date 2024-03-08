define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/customer-email-validator',
        'mage/url'
    ],
    function (
        Jquery,
        PaymentDefaultComponent,
        CustomerEmailValidator,
        MageUrl,
    ) {

        'use strict';

        return PaymentDefaultComponent.extend(
            {
                defaults: {
                    template: '',
                    checkoutConfig: {},
                    redirectAfterPlaceOrder: false,
                    controllerURL: "lunar/index/LunarRedirect",
                    logger: window.LunarLoggerHosted,
                    paymentButtonSelector: '.action.primary.checkout',
                    methodName: '',
                },

                redirectToPayment: function () {
                    if (!CustomerEmailValidator.validate()) {
                        return false;
                    }

                    let self = this;

                    let paymentConfig = this.checkoutConfig.config;

                    self.logger.setContext(paymentConfig, Jquery, MageUrl, this.methodName);

                /** Change default behavior after order. */
                self.afterPlaceOrder = async function () {
                    await Jquery.ajax({
                        type: "POST",
                        dataType: "json",
                        url: "/" + self.controllerURL,
                        data: {
                            quote_id: paymentConfig.custom.quoteId,
                        },
                        success: function(data) {
                            window.location.replace(data.paymentRedirectURL);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            self.submitError('<div class="lunarmobilepay-error">' + errorThrown + '</div>');
                        }
                    });
                }

                    self.placeOrder();
                },

                submitError: function (errorMessage) {
                    Jquery(`#${this.methodName}_messages`).prepend(errorMessage).show()
                },

                disablePaymentButton: function () {
                    Jquery(this.paymentButtonSelector).prop('disabled', true);
                },

                enablePaymentButton: function () {
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
                    if (!logoUrl) { return '';
                    }

                    return "  <img src='" + logoUrl + "' alt='mobilepay logo' style='height:5rem'>";
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
            }
        );
    }
);
