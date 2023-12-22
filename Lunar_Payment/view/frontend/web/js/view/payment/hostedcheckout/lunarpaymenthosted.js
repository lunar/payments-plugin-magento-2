define(
    [
        'Lunar_Payment/js/view/payment/hostedcheckout/hostedcheckout-component',
   ],
    function (
                HostedCheckout,
            ) {

        'use strict';

        return HostedCheckout.extend({
            defaults: {
                template: 'Lunar_Payment/payment/lunarpaymenthosted',
                checkoutConfig: window.checkoutConfig.lunarpaymenthosted,
            },
        });
    }
);
