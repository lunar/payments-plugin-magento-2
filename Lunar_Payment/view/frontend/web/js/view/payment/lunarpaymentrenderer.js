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
                type: 'lunarpaymentmethod',
                component: 'Lunar_Payment/js/view/payment/lunarpaymentmethod/lunarpayment-component'
            },
            {
                type: 'lunarmobilepay',
                component: 'Lunar_Payment/js/view/payment/mobilepay/lunarmobilepay-component'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);