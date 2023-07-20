require(
    [
        'uiComponent',
        'jquery'
   ],
    function (
                uiComponent,
                Jquery
           ) {

        'use strict';

        return uiComponent.extend({
            defaults: {
                template: 'Lunar_Payment/order_hostedcheckout',
                controllerURL: "lunar/index/CheckUnpaidOrders",
            },

            /** @inheritdoc */
            initialize: function () {
                this._super();

                this.checkPayments();
                // this.init();
            },

            init() {
                window.addEventListener('load', (e) => {
                })
            },

            checkPayments: function() {
                var self = this;
                
                Jquery.ajax({
                    type: "POST",
                    dataType: "json",
                    url: "/" + self.controllerURL,
                    data: {
                        args: args,
                    },
                    success: function(data) {
                        // 
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        self.submitError('<div class="lunar-error">' + errorThrown + '</div>');
                    }
                });
            },

            submitError: function(errorMessage) {
				Jquery('#lunarhosted_messages').prepend(errorMessage).show()
            },

        
        });
    }
);
