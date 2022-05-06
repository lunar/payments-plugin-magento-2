window.PluginLogger = {
    date: new Date().getTime(),
    context: {},
    setContext: function(context, Jquery, MageUrl) {
      if (!this.enabled()) {
        console.log("logs not enabled, stopping");
        return;
      }

      this.context = context;
      this.Jquery = Jquery;
      this.url = MageUrl;
    },
    log: async function(message, data = {}) {
      if (!this.enabled()) {
        console.log("logs not enabled, stopping");
        return;
      }

      const body = {
        message,
        data,
        date: this.date,
        context: this.context,
      }

      this.Jquery.ajax({
        url: this.url.build("lunar/index/Log"),
        type: 'POST',
        dataType: 'text',
        data: body
      });
    },
    enabled: function () {
      return window.checkoutConfig.config.custom.logsEnabled;
    }
  }
