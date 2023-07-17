require([
    'jquery'
],function(
    Jquery
    ) {

    Jquery(document).ajaxComplete(() => {

        if (Jquery(".aw-onestep-sidebar-wrapper button.action.primary.checkout").length > 0) {

            // LUNAR PAYMENT
            if (Jquery('#lunarpaymentmethod').is(":checked")) {
                addTriggerOnPrimaryButton('#lunarpaymentmethod-button');
            } else {
                Jquery('#lunarpaymentmethod').on('click', () => {
                    addTriggerOnPrimaryButton('#lunarpaymentmethod-button');
                });
            }
            

            // LUNAR MOBILEPAY
            if (Jquery('#lunarmobilepay').is(":checked")) {
                addTriggerOnPrimaryButton('#lunarmobilepay-button');
            } else {
                Jquery('#lunarmobilepay').on('click', () => {
                    addTriggerOnPrimaryButton('#lunarmobilepay-button');
                });
            }


            // HOSTED CHECKOUT
            if (Jquery('#lunarpaymenthosted').is(":checked")) {
                addTriggerOnPrimaryButton('#lunarpaymenthosted-button');
            } else {
                Jquery('#lunarpaymenthosted').on('click', () => {
                    addTriggerOnPrimaryButton('#lunarpaymenthosted-button');
                });
            }
            if (Jquery('#lunarmobilepayhosted').is(":checked")) {
                addTriggerOnPrimaryButton('#lunarmobilepayhosted-button');
            } else {
                Jquery('#lunarmobilepayhosted').on('click', () => {
                    addTriggerOnPrimaryButton('#lunarmobilepayhosted-button');
                });
            }

            function addTriggerOnPrimaryButton(buttonSelector) {
                Jquery(".aw-onestep-sidebar-wrapper button.action.primary.checkout")
                .off("click")
                .click((e) => {
                        e.preventDefault();
                        Jquery(buttonSelector).trigger('click');
                });
            }
        }
    });
});