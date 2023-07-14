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