window.LunarLogger = {
    date: new Date().getTime(),
    context: {},
    setContext: function (context, Jquery, MageUrl, isMobilePay = false) {
        if (!this.enabled()) {
            console.log("logs not enabled");
            return;
        }

        this.context = context;
        this.Jquery = Jquery;
        this.url = MageUrl;
        this.isMobilePay = isMobilePay;
    },

    log: async function (message, data = {}) {
        if (!this.enabled()) {
            console.log("logs not enabled");
            return;
        }

        const body = {
            message,
            data,
            date: this.date,
            context: this.context,
            method_code: this.isMobilePay ? 'lunarmobilepay' : '',
        }

        this.Jquery.ajax(
            {
                url: this.url.build("lunar/index/Log"),
                type: 'POST',
                dataType: 'text',
                data: body
            }
        );
    },

    enabled: function () {
        var methodName = this.isMobilePay ? 'lunarmobilepay' : 'lunarpaymentmethod';
        var methodConfig = window.checkoutConfig[methodName];
        return methodConfig?.logsEnabled;
    }
  }
