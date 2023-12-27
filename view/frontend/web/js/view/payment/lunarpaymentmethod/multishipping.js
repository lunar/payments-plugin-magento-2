require([
    'jquery',
    'Lunar_Payment/js/view/payment/lunarpaymentmethod/multishipping-callback',
    'Magento_Checkout/js/action/set-payment-information',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Ui/js/model/messages',
    'Magento_Checkout/js/model/quote'
],function(
    Jquery,
    multiShippingCallback,
    SetPaymentInformationAction,
    FullScreenLoader,
    MessagesContainer,
    Quote
    ) {
             // //  TEMPORARY CODE BEGIN // //
            // // After migration from Paylike is completed, remove the following

            const loadScript = function (url, callback) {
                let script;
                const scripts = Array.from(document.querySelectorAll('script'));
                const existingScript = scripts.find((script) => script.src === url);
                if (existingScript) {
                    script = existingScript;
                } else {
                    script = document.createElement('script');
                    script.type = 'text/javascript';
                    script.src = url;
                    document.getElementsByTagName('head')[0].appendChild(script);
                }
                
                if (script.readyState) {
                    script.onreadystatechange = () => {                
                        if (script.readyState === 'loaded' || script.readyState === 'complete') {
                            script.onreadystatechange = null;
                            callback();
                        }
                    };
                } else {
                    script.onload = () => callback();
                }
            };

            const checkScript = (url) => {
                const scripts = Array.from(document.querySelectorAll('script'));
                const existingScript = scripts.find((script) => script.src === url);

                return existingScript ?? false;
            };

            const removeScript = (url) => {
                let existingScript = checkScript(url);

                if (existingScript) {
                    existingScript.remove();
                    Paylike = undefined;
                }
            };     
            
            
            if (window.paymentMethod === 'lunarpaymentmethod') {
                removeScript('https://sdk.paylike.io/10.js');
                loadScript('https://sdk.paylike.io/a.js', () => {});
            }
            

            Quote.paymentMethod.subscribe(function(method) {
                if (method.method === 'lunarpaymentmethod') {
                    removeScript('https://sdk.paylike.io/10.js');
                    loadScript('https://sdk.paylike.io/a.js',  () => {});
                } 

                else if (method.method === 'paylikepaymentmethod') {

                    if (!checkScript('https://sdk.paylike.io/10.js')) {
                        removeScript('https://sdk.paylike.io/a.js');
                        loadScript('https://sdk.paylike.io/10.js', () => {});
                    } 
                }

            }, null, 'change');

            // //  TEMPORARY CODE END // // 


        Jquery("#review-button").off("click").on("click", (e) => {

            e.preventDefault();

            multiShippingCallback(function (err, res) {
                if (err) {
                    if(err === "closed") {
                        LunarLogger.log("Payment popup closed by user (multishipping)");
                    }
                    /**
                     * (Need improvement/rethink the logic)
                     * In "test" mode if user closes the popup, we need to refresh the page.
                     * Otherwise, the popup will initialize in live mode.
                     */
                     if ('test' === window.checkoutConfig.config.test) {
                        return location.reload();
                    }

                    return console.warn(err);
                }

                if (res.transaction.id !== undefined && res.transaction.id !== "") {

                    this.transactionid = res.transaction.id;

                    LunarLogger.log("Payment successfull (multishipping). Transaction ID: " + res.transaction.id);

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
                            LunarLogger.log("Order placed successfully (multishipping)");
                            /**
                             * Submit the multishipping overview form.
                             */
                            Jquery("#review-button").get(0).form.submit();
                        }
                    ).fail(
                        LunarLogger.log("Place order failed (multishipping).")
                    ).always(
                        function () {
                            FullScreenLoader.stopLoader();
                        }
                    );

                } else {
                    LunarLogger.log("No transaction id returned from payment gateway, order not placed (multishipping)");
                    return false;
                }
            });

        return  false;

    });
});
