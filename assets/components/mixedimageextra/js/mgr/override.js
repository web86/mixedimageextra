Ext.onReady(function () {

    //console.log("MYIMAGE AJAX HOOK INIT");

    var origRequest = Ext.Ajax.request;

    Ext.Ajax.request = function(options) {

        try {

            if (options && options.url && options.url.indexOf("mixedimage/connector.php") !== -1) {

                //console.log("INTERCEPT MIXEDIMAGE REQUEST");

                // безопасно пропускаем remove
                var isRemove = false;

                if (options.params && options.params.action === "file/remove") {
                    isRemove = true;
                }

                // ================================
                // 🚫 БЛОКИРУЕМ UPLOAD ДЛЯ НОВОГО РЕСУРСА
                // ================================
                if (!isRemove) {

                    var resourceId = 0;

                    // 1. Сначала пробуем взять id из formdata mixedImage
                    if (options.params && options.params.formdata) {
                        try {
                            var formdata = Ext.decode(options.params.formdata);
                            resourceId = parseInt(formdata.id || 0, 10);
                        } catch (e) {}
                    }

                    // 2. fallback: пробуем взять id из формы ресурса
                    if (!resourceId) {
                        try {
                            var panel = Ext.getCmp('modx-panel-resource');
                            if (panel && panel.getForm) {
                                var values = panel.getForm().getValues();
                                resourceId = parseInt(values.id || 0, 10);
                            }
                        } catch (e) {}
                    }

                    // Если ресурс ещё не сохранён — блокируем upload
                    if (!resourceId) {
                        Ext.Msg.alert(
                            'Внимание',
                            'Сначала сохраните ресурс, затем загружайте изображения.'
                        );
                        return false;
                    }

                    // ================================
                    // 🔁 ПОДМЕНА CONNECTOR
                    // ================================
                    console.log("REWRITING CONNECTOR URL");
                    options.url = MODx.config.assets_url + "components/mixedimageextra/connector.php";
                }
            }

        } catch (e) {
            console.warn("AJAX PATCH ERROR", e);
        }

        return origRequest.call(this, options);
    };

});