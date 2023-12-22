// //  TEMPORARY CODE BEGIN // //
// // After migration from Paylike is completed, remove the following

require([
        'jquery',
    ],
function(
        Jquery,
) {
    if (
        window.paymentMethod === 'lunarpaymenthosted'
        || window.paymentMethod === 'lunarmobilepayhosted'
    ) {
        /** maybe this bypass some frontend validations (?) - but we'll remove this file the next release */
        Jquery("#review-button").off("click").on("click", (e) => {
            e.preventDefault();

            Jquery("#review-button").closest('form').submit();
        });    
    }
});

// //  TEMPORARY CODE END // // 