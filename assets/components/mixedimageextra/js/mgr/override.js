Ext.onReady(function () {
    
    var origRequest = Ext.Ajax.request;
    
    // ================================
    // ПЕРЕХВАТЫВАЕМ UPLOAD (не migx)
    // ================================

    Ext.Ajax.request = function(options) {

        try {

            if (options && options.url && options.url.indexOf("mixedimage/connector.php") !== -1) {

                 // безопасно пропускаем remove
                var isRemove = false;

                if (options.params && options.params.action === "file/remove") {
                    isRemove = true;
                }

                // ================================
                // БЛОКИРУЕМ UPLOAD ДЛЯ НОВОГО РЕСУРСА
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

                    
                }
                
                // ================================
                // 🔁 ПОДМЕНА CONNECTOR
                // ================================
                //console.log("REWRITING CONNECTOR URL");
                options.url = MODx.config.assets_url + "components/mixedimageextra/connector.php";
            }

        } catch (e) {
            console.warn("AJAX PATCH ERROR", e);
        }
        
        if (options && options.params && options.params.action === 'file/remove') {
            // console.group('GLOBAL REMOVE REQUEST');
            // console.log('URL =', options.url);
            // console.log('PARAMS =', options.params);
            // console.log('OPTIONS =', options);
            // console.groupEnd();
        }
        return origRequest.call(this, options);
    };
    
    // ================================
    // ПЕРЕХВАТЫВАЕМ UPLOAD в MIGX
    // ================================
    /**
     * Получить MIGX_id из открытого окна редактирования строки MIGX.
     * Берём только видимое окно с полем .tvmigxid
     */
    function getCurrentMigxId() {
        try {
            var inputs = document.querySelectorAll('.x-window input.tvmigxid');

            if (!inputs || !inputs.length) {
                console.log('MIGX_ID: no .tvmigxid found');
                return 0;
            }

            // Берём последнее видимое поле — обычно это текущее открытое окно
            for (var i = inputs.length - 1; i >= 0; i--) {
                var input = inputs[i];

                if (!input) continue;

                var win = input.closest('.x-window');
                if (!win) continue;

                var style = window.getComputedStyle(win);
                var visible = style.display !== 'none' && style.visibility !== 'hidden';

                if (visible && input.value) {
                    var migxId = parseInt(input.value, 10) || 0;
                    //console.log('MIGX_ID FROM WINDOW:', migxId, input);
                    return migxId;
                }
            }
        } catch (e) {
            console.warn('getCurrentMigxId error', e);
        }

        return 0;
    }

    /**
     * Проверка: ресурс уже сохранён?
     */
    function getResourceId(formdataJson) {
        var resourceId = 0;

        // 1. пробуем из formdata
        if (formdataJson) {
            try {
                var formdata = Ext.decode(formdataJson);
                resourceId = parseInt(formdata.id || 0, 10);
            } catch (e) {}
        }

        // 2. fallback из формы ресурса
        if (!resourceId) {
            try {
                var panel = Ext.getCmp('modx-panel-resource');
                if (panel && panel.getForm) {
                    var values = panel.getForm().getValues();
                    resourceId = parseInt(values.id || 0, 10);
                }
            } catch (e) {}
        }

        return resourceId;
    }
    
    

    /**
     * Ждём, пока mixedimage загрузится
     */
    var tries = 0;
    var timer = setInterval(function () {

        if (!window.mixedimage || !mixedimage.fileform || !mixedimage.fileform.prototype) {
            tries++;
            if (tries > 50) {
                clearInterval(timer);
                console.warn('mixedimage.fileform not found');
            }
            return;
        }

        clearInterval(timer);
        //console.log('PATCHING mixedimage.fileform');

        /**
         * Патчим upload с кнопки "С вашего компьютера"
         */
        mixedimage.fileform.prototype.onFileSelected = function(field, value) {
            this.form.baseParams.file = field.getValue();

            var params = {};
            params.custompath = this.TV.getCustomPath() || '';
            params.formdata = Ext.util.JSON.encode(
                Ext.getCmp('modx-panel-resource').getForm().getValues() || {}
            );

            // Ресурс должен быть сохранён
            var resourceId = getResourceId(params.formdata);
            if (!resourceId) {
                Ext.Msg.alert(
                    'Внимание',
                    'Сначала сохраните ресурс, затем загружайте изображения.'
                );
                return false;
            }

            
            var isMigx = String(this.TV.tvId) !== String(this.TV.tv_id);
            
            if (isMigx) {
            
                var migxId = getCurrentMigxId();
            
                if (migxId) {
                    params.migx_id = migxId;
                    console.log('ATTACH MIGX_ID:', migxId);
                } else {
                    console.log('MIGX but no ID found');
                }
            
            } else {
                console.log('REGULAR TV upload');
            }

            this.form.submit({
                url: MODx.config.assets_url + 'components/mixedimageextra/connector.php',
                waitMsg: 'Uploading...',
                params: params,
                success: function(fp, o) {
                    //console.log('UPLOAD RESPONSE:', o.result);
                    var value = o.result.message;
                    //console.log('UPLOAD MESSAGE:', value);
                    this.TV.setValue(value);
                    
                    this.fireEvent('onFileUploadSuccess', o.result);
                },
                failure: function(fp, o) {
                    MODx.msg.alert('Error', o.result.message);
                },
                scope: this
            });
        };

        
        /**
         * Патчим удаление, чтобы тоже шло через наш connector
         */
        // Переопределяем стандартный clearField() у mixedImage,
        // чтобы при удалении файла запрос шёл не в родной connector mixedImage,
        // а в наш кастомный mixedimageextra/connector.php.
        // Это нужно для того, чтобы вместе с основным файлом удалялись
        // и все его сгенерированные копии (-S, -M, -L и т.д.).
        mixedimage.trigger.prototype.clearField = function() {
            var tvIdRaw = '';
            var fieldTvId = 0;
        
            try {
                // У тебя по логам именно тут лежит строка вида:
                // inp_628_138_1
                tvIdRaw = this.tvId || '';
        
                // Достаём из строки второй числовой сегмент = реальный ID TV
                // Формат: inp_<resourceId>_<tvId>_<index>
                var match = String(tvIdRaw).match(/^inp_\d+_(\d+)_\d+$/);
        
                if (match && match[1]) {
                    fieldTvId = parseInt(match[1], 10) || 0;
                }
            } catch (e) {
                console.warn('mixedimage clearField parse error', e);
            }
        
            // console.group('MIXEDIMAGE REMOVE DEBUG');
            // console.log('this =', this);
            // console.log('tvIdRaw =', tvIdRaw);
            // console.log('fieldTvId =', fieldTvId);
            // console.log('value =', this.value);
            // console.groupEnd();
        
            if (this.removeFile && this.value) {
                Ext.Ajax.request({
                    url: MODx.config.assets_url + 'components/mixedimageextra/connector.php',
                    params: {
                        file: this.value,
                        action: 'file/remove',
                        source: this.source,
                        tvId: tvIdRaw,
                        tv_id: fieldTvId
                    },
                    success: function(response) {
                        // console.log('REMOVE SUCCESS response =', response);
                        MODx.msg.alert('Remove', _('mixedimage.success_removed'));
                    },
                    failure: function(response) {
                        console.log('REMOVE FAILURE response =', response);
                        MODx.msg.alert('Error', _('mixedimage.error_remove'));
                    }
                });
            }
        
            this.setValue('');
            this.fireEvent('change', this);
        };

        console.log('mixedimage.fileform patched successfully');

    }, 200);
    
});
