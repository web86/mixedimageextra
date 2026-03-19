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
    public function process($filePath, $config)
    {
        if (!file_exists($filePath)) return;

        // проверяем что это изображение
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) return;

        
        // ================================
        // 📥 ОТКРЫВАЕМ ОРИГИНАЛ
        // ================================
        
        
        try {
            $original = new Imagick($filePath);
        } catch (Exception $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Imagick open error: '.$e->getMessage());
            return;
        }

        // сохраняем ВСЕ профили (важно!)
        $profiles = [];

        try {
            $profiles = $original->getImageProfiles('*', true);
        } catch (Exception $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Profiles error: '.$e->getMessage());
        }

        // переводим в jpg
        $original->setImageFormat('jpeg');
        $original->setImageCompression(Imagick::COMPRESSION_JPEG);

        $pathInfo = pathinfo($filePath);

        // ================================
        // 🔄 СОЗДАЕМ ВЕРСИИ
        // ================================
        
        foreach ($config['sizes'] as $size) {

            $suffix = $size['suffix'] ?? '';
            $w = (int)($size['w'] ?? 0);
            $h = (int)($size['h'] ?? 0);
        
            $img = clone $original;
        
            // если заданы размеры — кроп
            if ($w > 0 && $h > 0) {
                $img->cropThumbnailImage($w, $h);
            }
        
            // качество
            $quality = isset($config['quality']) ? (int)$config['quality'] : 85;
            $img->setImageCompressionQuality($quality);
        
            // профили
            foreach ($profiles as $name => $profile) {
                try {
                    $img->profileImage($name, $profile);
                } catch (Exception $e) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, 'Profile apply error: '.$e->getMessage());
                }
            }
        
            $newName = $pathInfo['filename'] . $suffix . '.jpg';
            $newPath = $pathInfo['dirname'] . '/' . $newName;
        
            $img->writeImage($newPath);
        
            $img->clear();
            $img->destroy();
        }

       

        $original->clear();
        $original->destroy();
        
        // ================================
        // 🗑 УДАЛЯЕМ ОРИГИНАЛ (если не jpg)
        // ================================
        
        if (!in_array($ext, ['jpg','jpeg'])) {
        
            if (file_exists($filePath)) {
        
                // защита — удаляем только png/webp
                if (preg_match('/\.(png|webp)$/i', $filePath)) {
        
                    unlink($filePath);
        
                    //$this->modx->log(modX::LOG_LEVEL_ERROR, 'DELETED ORIGINAL: '.$filePath);
                }
            }
        }
    }
}