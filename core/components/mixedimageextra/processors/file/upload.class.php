<?php

/**
 * Custom upload processor for mixedImage
 * Добавляет пост-обработку изображений (resize, crop, convert)
 */

require_once MODX_CORE_PATH . 'components/mixedimage/processors/file/upload.class.php';
require_once MODX_CORE_PATH . 'components/mixedimageextra/service/ImageProcessor.php';

class mixedimageextraFileUploadProcessor extends mixedimageBrowserFileUploadProcessor
{
    
    /** @var bool Debug режим */
    protected $debug = false;

    /**
     * Debug лог (только если включен mixedimageextra_debug)
     */
    protected function log($msg, $data = null)
    {
        if (!$this->debug) return;

        if ($data !== null) {
            $msg .= ' | ' . (is_scalar($data) ? $data : print_r($data, true));
        }

        $this->modx->log(modX::LOG_LEVEL_ERROR, '[mixedimageextra] ' . $msg);
    }

    /**
     * Ошибки пишем всегда (важно для продакшена)
     */
    protected function logError($msg)
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[mixedimageextra] ' . $msg);
    }

    /**
     * Основной процесс загрузки
     */
    public function process()
    {
        
        // ================================
        // 🔧 DEBUG режим (системная настройка)
        // ================================
        $this->debug = (bool)$this->modx->getOption('mixedimageextra_debug', null, false);
        
        $this->log('PROCESSOR START');
        $this->log('REQUEST: '.print_r($_REQUEST,1));

        // ================================
        // 📥 Стандартная загрузка файла
        // ================================
        $response = parent::process();

        // ================================
        // 📦 Нормализация response
        // ================================
        if (is_object($response) && method_exists($response, 'getResponse')) {
            $data = $response->getResponse();
        }
        elseif (is_array($response)) {
            $data = $response;
        }
        elseif (is_string($response)) {
            $data = json_decode($response, true);

            if (!is_array($data)) {
                $this->logError('Invalid JSON response');
                return $response;
            }
        }
        else {
            $this->logError('Unknown response type');
            return $response;
        }

        // ================================
        // ❗ Проверка успешной загрузки
        // ================================
        if (empty($data['success'])) {
            $this->log('Upload failed');
            return $response;
        }

        // ================================
        // ❗ Проверка Imagick
        // ================================
        if (!class_exists('Imagick')) {
            $this->logError('Imagick not installed');
            return $response;
        }

        try {

            // ================================
            // 📌 Получаем путь к файлу
            // ================================
            if (empty($data['message'])) {
                $this->logError('Empty file URL');
                return $response;
            }

            $url = $data['message'];
            $this->log('URL', $url);

            // убираем base_url если нужно
            $baseUrl = $this->source->getBaseUrl();

            if ($baseUrl && strpos($url, $baseUrl) === 0) {
                $url = str_replace($baseUrl, '', $url);
            }

            // формируем полный путь
            $basePath = MODX_BASE_PATH;
            
            $fullPath = MODX_BASE_PATH . ltrim($url, '/');
            
            if (!file_exists($fullPath)) {
                $this->logError('FILE NOT FOUND: ' . $fullPath);
                return $response;
            }

            $this->log('FILE', $fullPath);

            // ================================
            // MIGX DETECT
            // ================================
            
            
            $tvIdRaw = $this->getProperty('tvId'); // inp_559_135_1
            $fieldTvId = (int)$this->getProperty('tv_id');
            
            $isMigx = ((string)$this->getProperty('tvId') !== (string)$this->getProperty('tv_id'));

            $this->log('IS MIGX', $isMigx ? 'YES' : 'NO');
            
            
            // ================================
            // MIGX INDEX
            // ================================
            $migxIndex = 0;
            
            if ($isMigx) {

                // 1. из JS
                $migxIndex = (int)($_REQUEST['migx_id'] ?? 0);

                // 2. fallback (новая строка)
                if (!$migxIndex) {

                    if (preg_match('/inp_\d+_(\d+)_\d+/', $tvIdRaw, $m)) {

                        $migxTvId = (int)$m[1];
                        $tvKey = 'tv' . $migxTvId;

                        $formdataRaw = $_REQUEST['formdata'] ?? '';
                        $formdata = json_decode($formdataRaw, true);

                        if (!empty($formdata[$tvKey])) {

                            $rows = json_decode($formdata[$tvKey], true);

                            if (is_array($rows) && !empty($rows)) {

                                $last = end($rows);
                            
                                if (!empty($last['MIGX_id'])) {
                                    $migxIndex = (int)$last['MIGX_id'] + 1;
                                }
                            }
                        }
                    }
                }

                $this->log('MIGX INDEX FINAL', $migxIndex);
                
            }
            
            
            
            // ================================
            // 📌 Получаем конфиг из TV
            // ================================
                    
            $TV = $this->modx->getObject('modTemplateVar', $fieldTvId);
            

            $config = [];

            if ($TV) {

                // --------------------------------
                // 1. Пытаемся взять JSON из elements
                // --------------------------------
                $elements = $TV->get('elements');
                $this->log('RAW elements', $elements);

                $tmp = json_decode($elements, true);

                if (is_array($tmp) && !empty($tmp['imageConfig'])) {
                    $this->log('ELEMENTS JSON', $tmp);
                    $config = $tmp['imageConfig'];
                }

                // --------------------------------
                // 2. fallback → input_properties
                // --------------------------------
                if (empty($config)) {

                    $this->log('Fallback to input_properties');

                    if (!empty($TV->input_properties)) {
                        $tmp = @unserialize($TV->input_properties);

                        if (is_array($tmp) && !empty($tmp['imageConfig'])) {
                            $config = $tmp['imageConfig'];
                        }
                    }
                }
            }

            $this->log('FINAL CONFIG', $config);

            // если нет конфига — ничего не делаем
            if (empty($config)) {
                return $response;
            }

            // проверка размеров
            if (empty($config['sizes']) || !is_array($config['sizes'])) {
                $this->logError('Invalid sizes config');
                return $response;
            }

            // ================================
            // 🚀 Обработка изображения
            // ================================
            $processor = new ImageProcessor($this->modx);
            
            $result = $processor->process($fullPath, $config, $migxIndex, $this->getProperty('res_alias'));
            
            $this->log('$result', print_r($result,1));

            if (!$result || empty($result['baseName'])) {
                $this->logError('ImageProcessor failed');
                return $response;
            }
            
            $urlInfo = pathinfo($url);
            $newUrl = $urlInfo['dirname'] . '/' . $result['baseName'] . '.jpg';
            
            
            $this->log('FINAL URL', $newUrl);
            
            $data['message'] = $newUrl;
            $data['success'] = true;
            
            
           
        } catch (Exception $e) {
            $this->logError($e->getMessage());
        }

        return $this->modx->toJSON($data);
    }
}

return 'mixedimageextraFileUploadProcessor';
