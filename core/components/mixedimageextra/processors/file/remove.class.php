<?php

require_once MODX_CORE_PATH . 'components/mixedimage/processors/file/remove.class.php';
require_once MODX_CORE_PATH . 'components/mixedimageextra/service/ImageProcessor.php';

class mixedimageextraFileRemoveProcessor extends mixedimageBrowserFileRemoveProcessor
{
    protected function dbg($msg, $data = null)
    {
        // Включай через системную настройку mixedimageextra_debug = 1
        if (!(bool)$this->modx->getOption('mixedimageextra_debug', null, false)) {
            return;
        }
    
        if ($data !== null) {
            $msg .= ' | ' . (is_scalar($data) ? $data : print_r($data, true));
        }
    
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[mixedimageextra remove] ' . $msg);
    }

    protected function normalizeArray($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        $unserialized = @unserialize($value);
        if (is_array($unserialized)) {
            return $unserialized;
        }

        return [];
    }

    protected function findImageConfigRecursive($data)
    {
        if (!is_array($data)) {
            return [];
        }

        if (!empty($data['imageConfig']) && is_array($data['imageConfig'])) {
            return $data['imageConfig'];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findImageConfigRecursive($value);
                if (!empty($found)) {
                    return $found;
                }
            }
        }

        return [];
    }

    /**
     * Ищем imageConfig непосредственно у TV
     * 1) elements
     * 2) input_properties
     * 3) output_properties
     */
    protected function getDirectImageConfigFromTv(modTemplateVar $TV)
    {
        // elements
        $elements = $TV->get('elements');
        $this->dbg('RAW elements', $elements);
        $tmp = $this->normalizeArray($elements);
        $config = $this->findImageConfigRecursive($tmp);
        if (!empty($config)) {
            $this->dbg('CONFIG from elements', $config);
            return $config;
        }

        // input_properties
        $inputProperties = $TV->get('input_properties');
        $this->dbg('RAW input_properties', $inputProperties);
        $tmp = $this->normalizeArray($inputProperties);
        $config = $this->findImageConfigRecursive($tmp);
        if (!empty($config)) {
            $this->dbg('CONFIG from input_properties', $config);
            return $config;
        }

        // output_properties
        $outputProperties = $TV->get('output_properties');
        $this->dbg('RAW output_properties', $outputProperties);
        $tmp = $this->normalizeArray($outputProperties);
        $config = $this->findImageConfigRecursive($tmp);
        if (!empty($config)) {
            $this->dbg('CONFIG from output_properties', $config);
            return $config;
        }

        return [];
    }

    protected function getMigxConfigNamesFromTv(modTemplateVar $TV)
    {
        $inputProperties = $this->normalizeArray($TV->get('input_properties'));
        $configsRaw = '';

        if (!empty($inputProperties['configs'])) {
            $configsRaw = (string)$inputProperties['configs'];
        }

        if ($configsRaw === '') {
            return [];
        }

        $parts = preg_split('/\s*(\|\||,)\s*/', $configsRaw);
        $parts = array_filter(array_map('trim', (array)$parts));

        $this->dbg('MIGX config names', $parts);

        return array_values($parts);
    }

    protected function loadMigxPackage()
    {
        static $loaded = null;

        if ($loaded !== null) {
            return $loaded;
        }

        $modelPath = MODX_CORE_PATH . 'components/migx/model/';
        $loaded = $this->modx->addPackage('migx', $modelPath);

        $this->dbg('MIGX package loaded', $loaded ? 'YES' : 'NO');

        return $loaded;
    }

    protected function getImageConfigFromMigxConfigName($configName)
    {
        $configName = trim((string)$configName);
        if ($configName === '') {
            return [];
        }

        if (!$this->loadMigxPackage()) {
            $this->dbg('Cannot load MIGX package');
            return [];
        }

        /** @var xPDOObject $migxConfig */
        $migxConfig = $this->modx->getObject('migxConfig', ['name' => $configName]);

        if (!$migxConfig) {
            $this->dbg('MIGX config object not found', $configName);
            return [];
        }

        $formtabsRaw = $migxConfig->get('formtabs');
        $this->dbg('MIGX formtabs raw', $formtabsRaw);

        $formtabs = $this->normalizeArray($formtabsRaw);
        if (empty($formtabs) || !is_array($formtabs)) {
            $this->dbg('MIGX formtabs empty', $configName);
            return [];
        }

        foreach ($formtabs as $tab) {
            if (empty($tab['fields']) || !is_array($tab['fields'])) {
                continue;
            }

            foreach ($tab['fields'] as $field) {
                $inputTV = trim((string)($field['inputTV'] ?? ''));
                $fieldName = trim((string)($field['field'] ?? ''));
                $inputTVtype = trim((string)($field['inputTVtype'] ?? ''));

                $this->dbg('MIGX field inspect', [
                    'field' => $fieldName,
                    'inputTV' => $inputTV,
                    'inputTVtype' => $inputTVtype,
                ]);

                // Иногда imageConfig могут положить прямо в field JSON
                $fieldConfig = $this->findImageConfigRecursive($field);
                if (!empty($fieldConfig)) {
                    $this->dbg('CONFIG from MIGX field directly', $fieldConfig);
                    return $fieldConfig;
                }

                // Главный сценарий: поле MIGX ссылается на обычный TV через inputTV
                if ($inputTV !== '') {
                    /** @var modTemplateVar $innerTV */
                    $innerTV = $this->modx->getObject('modTemplateVar', ['name' => $inputTV]);

                    if (!$innerTV) {
                        $this->dbg('Inner TV not found', $inputTV);
                        continue;
                    }

                    $this->dbg('Inner TV found', [
                        'name' => $innerTV->get('name'),
                        'id' => $innerTV->get('id'),
                    ]);

                    $innerConfig = $this->getDirectImageConfigFromTv($innerTV);
                    if (!empty($innerConfig)) {
                        $this->dbg('CONFIG from inner TV', [
                            'tv' => $innerTV->get('name'),
                            'config' => $innerConfig,
                        ]);
                        return $innerConfig;
                    }
                }
            }
        }

        $this->dbg('No imageConfig inside MIGX config', $configName);
        return [];
    }

    /**
     * Общий resolver:
     * 1) пытаемся взять config у текущего TV
     * 2) если пусто и это MIGX TV — идём через input_properties["configs"]
     * 3) в MIGX config ищем поле с inputTV и уже у внутреннего TV берём imageConfig
     */
    protected function getTvConfig($tvId)
    {
        if (!$tvId) {
            $this->dbg('getTvConfig: empty tvId');
            return [];
        }

        /** @var modTemplateVar $TV */
        $TV = $this->modx->getObject('modTemplateVar', (int)$tvId);
        if (!$TV) {
            $this->dbg('TV not found', $tvId);
            return [];
        }

        $this->dbg('Resolve config for TV', [
            'id' => $TV->get('id'),
            'name' => $TV->get('name'),
            'type' => $TV->get('type'),
        ]);

        // 1. прямой config у самого TV
        $config = $this->getDirectImageConfigFromTv($TV);
        if (!empty($config)) {
            return $config;
        }

        // 2. fallback: если это MIGX TV, смотрим его configs => GalleryTEST
        $migxConfigNames = $this->getMigxConfigNamesFromTv($TV);

        foreach ($migxConfigNames as $configName) {
            $config = $this->getImageConfigFromMigxConfigName($configName);
            if (!empty($config)) {
                $this->dbg('FINAL CONFIG from MIGX config', $config);
                return $config;
            }
        }

        $this->dbg('FINAL CONFIG not found');
        return [];
    }

    protected function normalizeResponse($response)
    {
        if (is_object($response) && method_exists($response, 'getResponse')) {
            $response = $response->getResponse();
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : null;
        }

        if (is_array($response)) {
            return $response;
        }

        return null;
    }

    public function process()
    {
        $this->dbg('PROCESSOR START');
        $this->dbg('REQUEST', $_REQUEST);

        $url = (string)$this->getProperty('file');
        $tvIdRaw = (string)$this->getProperty('tvId');
        $fieldTvId = (int)$this->getProperty('tv_id');
        
        // Для обычного mixedimage TV tvId может быть просто числом, например "137"
        if (!$fieldTvId && ctype_digit($tvIdRaw)) {
            $fieldTvId = (int)$tvIdRaw;
        }
        
        // Для MIGX tvId может быть строкой вида inp_590_138_1
        if (!$fieldTvId && preg_match('/^inp_\d+_(\d+)_\d+$/', $tvIdRaw, $m)) {
            $fieldTvId = (int)$m[1];
        }
        
        $this->dbg('URL', $url);
        $this->dbg('tvIdRaw', $tvIdRaw);
        $this->dbg('fieldTvId', $fieldTvId);

        if ($url === '') {
            $this->dbg('Empty URL, fallback to parent only');
            return parent::process();
        }

        $baseUrl = '';
        if ($this->source && method_exists($this->source, 'getBaseUrl')) {
            $baseUrl = (string)$this->source->getBaseUrl();
        }

        if ($baseUrl && strpos($url, $baseUrl) === 0) {
            $url = substr($url, strlen($baseUrl));
        }

        $fullPath = MODX_BASE_PATH . ltrim($url, '/');
        $this->dbg('FULL PATH', $fullPath);

        $config = $this->getTvConfig($fieldTvId);
        $this->dbg('FINAL CONFIG', $config);

        $response = parent::process();
        $data = $this->normalizeResponse($response);
        $this->dbg('PARENT RESPONSE', $data);

        if (!is_array($data) || empty($data['success'])) {
            $this->dbg('Parent remove failed or unknown response');
            return $response;
        }

        if (empty($config['sizes']) || !is_array($config['sizes'])) {
            $this->dbg('No valid imageConfig, only main file removed');
            return $response;
        }

        try {
            $processor = new ImageProcessor($this->modx);
            $this->dbg('CALL removeGeneratedFiles');
            $removeResult = $processor->removeGeneratedFiles($fullPath, $config);
            $this->dbg('REMOVE RESULT', $removeResult);
        } catch (Exception $e) {
            $this->dbg('EXCEPTION', $e->getMessage());
        }

        return $response;
    }
}

return 'mixedimageextraFileRemoveProcessor';
