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

    $original->setImageFormat('jpeg');
    $original->setImageCompression(Imagick::COMPRESSION_JPEG);
    
    
    //$this->modx->log(modX::LOG_LEVEL_ERROR, '$filePath: '.$filePath);
    
    
    
    
    
    if (!$alias) {
        // fallback (на всякий случай)
        $alias = pathinfo($filePath, PATHINFO_FILENAME);
    }
    
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

        if ($w > 0 && $h > 0) {
            $img->cropThumbnailImage($w, $h);
        } elseif ($w > 0) {
            $img->thumbnailImage($w, 0, true, true);
        } elseif ($h > 0) {
            $img->thumbnailImage(0, $h, true, true);
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
}
