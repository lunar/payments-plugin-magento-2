window.LunarLoggerHosted = {
    date: new Date().getTime(),
    context: {},
    setContext: function (context, Jquery, MageUrl, methodName) {
        if (!this.enabled()) {
            console.log("logs not enabled");
            return;
        }

        this.context = context;
        this.Jquery = Jquery;
        this.url = MageUrl;
        this.methodName = methodName;
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
            method_code: this.methodName,
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
        var methodConfig = window.checkoutConfig[this.methodName];
        return methodConfig?.logsEnabled;
    }
  }
