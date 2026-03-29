<?php

class ImageProcessor
{
    protected $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Главный метод обработки изображения
     */
    public function process($filePath, $config, $migxIndex = 0,$alias)
    {
    if (!file_exists($filePath)) return false;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) return false;

    try {
        $original = new Imagick($filePath);
    } catch (Exception $e) {
        $this->modx->log(modX::LOG_LEVEL_ERROR, 'Imagick open error: '.$e->getMessage());
        return false;
    }

    // профили
    $profiles = [];
    try {
        $profiles = $original->getImageProfiles('*', true);
    } catch (Exception $e) {}

    //$this->modx->log(modX::LOG_LEVEL_ERROR, '$filePath: '.$filePath);
    
    // определяем ориентацию картинки
    $original->autoOrient();

    $sourceW = $original->getImageWidth();
    $sourceH = $original->getImageHeight();
    $isPortrait = ($sourceH > $sourceW);
    
    $original->setImageFormat('jpeg');
    $original->setImageCompression(Imagick::COMPRESSION_JPEG);
    
    if (!$alias) {
        // fallback (на всякий случай)
        $alias = pathinfo($filePath, PATHINFO_FILENAME);
    }
    
    // $this->modx->log(modX::LOG_LEVEL_ERROR, '$sourceW: '.$sourceW);
    // $this->modx->log(modX::LOG_LEVEL_ERROR, '$sourceH: '.$sourceH);
    // $this->modx->log(modX::LOG_LEVEL_ERROR, '$isPortrait: '.$isPortrait);
    
    // формируем basename
    $baseName = $alias;
    
    // если это MIGX и не первый элемент
    if ($migxIndex > 1) {
        $baseName .= '-' . $migxIndex;
    }

    //$this->modx->log(modX::LOG_LEVEL_ERROR, 'FINAL BASENAME: '.$baseName);

    $generated = [];

    foreach ($config['sizes'] as $size) {

        $suffix = $size['suffix'] ?? '';
        $w = (int)($size['w'] ?? 0);
        $h = (int)($size['h'] ?? 0);
        
        $img = clone $original;
        
        try {
            if ($isPortrait) {
                // Для вертикальных: не кропаем, а ресайзим по высоте
                if ($h > 0) {
                    $srcW = $img->getImageWidth();
                    $srcH = $img->getImageHeight();
        
                    $newW = (int) round(($srcW / $srcH) * $h);
                    if ($newW < 1) {
                        $newW = 1;
                    }
        
                    $img->thumbnailImage($newW, $h, true);
                } elseif ($w > 0) {
                    $srcW = $img->getImageWidth();
                    $srcH = $img->getImageHeight();
        
                    $newH = (int) round(($srcH / $srcW) * $w);
                    if ($newH < 1) {
                        $newH = 1;
                    }
        
                    $img->thumbnailImage($w, $newH, true);
                }
                // если w=0 и h=0 — ничего не делаем, сохраняем как есть
            } else {
                // Для горизонтальных оставляем старую логику
                if ($w > 0 && $h > 0) {
                    $img->cropThumbnailImage($w, $h);
                } elseif ($w > 0) {
                    $srcW = $img->getImageWidth();
                    $srcH = $img->getImageHeight();
        
                    $newH = (int) round(($srcH / $srcW) * $w);
                    if ($newH < 1) {
                        $newH = 1;
                    }
        
                    $img->thumbnailImage($w, $newH, true);
                } elseif ($h > 0) {
                    $srcW = $img->getImageWidth();
                    $srcH = $img->getImageHeight();
        
                    $newW = (int) round(($srcW / $srcH) * $h);
                    if ($newW < 1) {
                        $newW = 1;
                    }
        
                    $img->thumbnailImage($newW, $h, true);
                }
            }
        } catch (Exception $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ImageProcessor] resize error | suffix=' . $suffix .
                ' | w=' . $w .
                ' | h=' . $h .
                ' | isPortrait=' . (int)$isPortrait .
                ' | message=' . $e->getMessage()
            );
            return false;
        }

        $quality = isset($config['quality']) ? (int)$config['quality'] : 85;
        $img->setImageCompressionQuality($quality);

        foreach ($profiles as $name => $profile) {
            try {
                $img->profileImage($name, $profile);
            } catch (Exception $e) {}
        }

        $newName = $baseName . $suffix . '.jpg';
        $targetDir = dirname($filePath);
        
        $newPath = $targetDir . '/' . $newName;
        //$this->modx->log(modX::LOG_LEVEL_ERROR, '$newPath: '.$newPath);
        
        $img->writeImage($newPath);

        $generated[] = $newName;

        $img->clear();
        $img->destroy();
    }

    $original->clear();
    $original->destroy();

    // удалить оригинал
    if (!in_array($ext, ['jpg','jpeg'])) {
        if (preg_match('/\.(png|webp)$/i', $filePath)) {
            @unlink($filePath);
        }
    }

    // ВАЖНО: возвращаем результат
    return [
        'baseName' => $baseName,
        'files' => $generated,
    ];
}

    
    
    /**
     * Удаляет только текущий файл и его производные по imageConfig
     *
     * @param string $filePath Абсолютный путь к текущему файлу
     * @param array $config imageConfig
     * @return array
    */
    
    public function removeGeneratedFiles($filePath, array $config = [])
{
    $result = [
        'requested' => [],
        'deleted'   => [],
        'failed'    => [],
        'missing'   => [],
    ];

    // $this->modx->log(modX::LOG_LEVEL_ERROR, '[ImageProcessor remove] START | ' . $filePath);
    // $this->modx->log(modX::LOG_LEVEL_ERROR, '[ImageProcessor remove] CONFIG | ' . print_r($config, true));

    if (!$filePath) {
        return $result;
    }

    $dir = dirname($filePath);
    $baseName = pathinfo($filePath, PATHINFO_FILENAME);

    $targets = [];

    if (!empty($config['sizes']) && is_array($config['sizes'])) {
        foreach ($config['sizes'] as $size) {
            $suffix = isset($size['suffix']) ? (string)$size['suffix'] : '';

            // базовый файл уже удалён parent::process()
            if ($suffix === '') {
                continue;
            }

            $targets[] = $dir . '/' . $baseName . $suffix . '.jpg';
        }
    }

    $targets = array_values(array_unique($targets));
    $result['requested'] = $targets;

    // $this->modx->log(modX::LOG_LEVEL_ERROR, '[ImageProcessor remove] TARGETS | ' . print_r($targets, true));

    foreach ($targets as $target) {
        clearstatcache(true, $target);

        if (!file_exists($target)) {
            $result['missing'][] = $target;
            continue;
        }

        $unlinkOk = @unlink($target);

        clearstatcache(true, $target);
        $stillExists = file_exists($target);

        if ($unlinkOk && !$stillExists) {
            $result['deleted'][] = $target;
        } else {
            $result['failed'][] = [
                'file' => $target,
                'unlink_return' => $unlinkOk ? 'true' : 'false',
                'exists_after' => $stillExists ? 'true' : 'false',
                'perms' => substr(sprintf('%o', @fileperms($target)), -4),
                'owner' => @fileowner($target),
                'writable' => is_writable($target) ? 'true' : 'false',
            ];
        }
    }

    // $this->modx->log(modX::LOG_LEVEL_ERROR, '[ImageProcessor remove] RESULT | ' . print_r($result, true));

    return $result;
}
}
