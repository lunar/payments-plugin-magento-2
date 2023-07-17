define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'lunarpaymenthosted',
                component: 'Lunar_Payment/js/view/payment/hostedcheckout/lunarhosted-component'
            },
            {
                type: 'lunarmobilepayhosted',
                component: 'Lunar_Payment/js/view/payment/hostedcheckout/mobilepayhosted-component'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);