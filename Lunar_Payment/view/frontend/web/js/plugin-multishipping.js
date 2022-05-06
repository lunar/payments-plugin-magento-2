require([
    'jquery',
    'Lunar_Payment/js/multishipping-callback',
    'Magento_Checkout/js/action/set-payment-information',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Ui/js/model/messages'
],function(
    Jquery,
    multiShippingCallback,
    SetPaymentInformationAction,
    FullScreenLoader,
    MessagesContainer,
    ) {
        Jquery("#review-button").off("click").on("click", (e) => {

            e.preventDefault();

            multiShippingCallback(function (err, res) {
                if (err) {
                    if(err === "closed") {
                        PluginLogger.log("Payment popup closed by user");
                    }
                    /**
                     * (Need improvement/rethink the logic)
                     * If user closes the popup, we need to refresh the page.
                     * In "test" mode if user closes the popup, we need to refresh the page.
                     */
                     if ('test' === window.checkoutConfig.config.test) {
                        return location.reload();
                    }

                    return console.warn(err);
                }

                if (res.transaction.id !== undefined && res.transaction.id !== "") {

                    this.transactionid = res.transaction.id;

                    PluginLogger.log("Payment successfull. Transaction ID: " + res.transaction.id);

                    /** Add extra data to be used on quote. */
                    extraData = {
                        "method": window.paymentMethod,
                        "additional_data": {
                            'transactionid': this.transactionid
                        }
                    };
                    Jquery.when(
                        SetPaymentInformationAction(MessagesContainer, extraData)
                    ).done(
                        function () {
                            FullScreenLoader.stopLoader();
                            PluginLogger.log("Order placed successfully");
                            /**
                             * Submit the multishipping overview form.
                             */
                            Jquery("#review-button").get(0).form.submit();
                        }
                    ).fail(
                        PluginLogger.log("Place order failed.")
                    ).always(
                        function () {
                            FullScreenLoader.stopLoader();
                        }
                    );

                } else {
                    PluginLogger.log("No transaction id returned from payment gateway, order not placed");
                    return false;
                }
            });

        return  false;

    });
});
