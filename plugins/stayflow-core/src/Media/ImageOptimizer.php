<?php

declare(strict_types=1);

namespace StayFlow\Media;

/**
 * Version: 1.0.0
 * RU: Оптимизация изображений и умное наложение Watermark для квартир.
 * EN: Image optimization and smart watermarking for apartments.
 */
final class ImageOptimizer
{
    public function register(): void
    {
        // 1. Максимальный размер и качество
        add_filter('big_image_size_threshold', fn() => 1920);
        add_filter('jpeg_quality', fn() => 82);
        add_filter('wp_editor_set_quality', fn() => 82);

        // 2. Нативный WebP
        add_filter('wp_image_editors', fn($editors) => $editors);
        add_filter('image_editor_output_format', function ($formats) {
            $formats['image/jpeg'] = 'image/webp';
            return $formats;
        });

        // 3. Исключаем PNG из конвертации (для логотипов)
        add_filter('image_editor_output_format', function ($formats) {
            unset($formats['image/png']);
            return $formats;
        }, 20);

        // 4. Умный Watermark (Только для загрузок из форм StayFlow)
        add_filter('wp_handle_upload', [$this, 'applyWatermark']);
    }

    public function applyWatermark(array $upload): array
    {
        // Проверяем, что загрузка идет именно с нашей формы (флаг ставится в Handler)
        // И проверяем, что нет ошибки загрузки
        if (!defined('SF_APPLY_WATERMARK') || !SF_APPLY_WATERMARK || isset($upload['error'])) {
            return $upload;
        }

        $filePath = $upload['file'];
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Работаем только с jpg, png, webp
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $upload;
        }

        $watermarkPath = WP_CONTENT_DIR . '/uploads/2025/12/gorizontal-color-4.webp';
        
        if (!file_exists($watermarkPath)) {
            return $upload; // Если логотипа нет, просто пропускаем
        }

        // Создаем ресурс изображения в зависимости от формата
        $imageString = file_get_contents($filePath);
        if (!$imageString) return $upload;
        
        $image = @imagecreatefromstring($imageString);
        $watermark = @imagecreatefromwebp($watermarkPath);

        if ($image && $watermark) {
            $imgW = imagesx($image);
            $imgH = imagesy($image);
            $wtmW = imagesx($watermark);
            $wtmH = imagesy($watermark);

            // Масштабируем вотермарк до 25% от ширины фото
            $newWtmW = $imgW * 0.25;
            $newWtmH = ($newWtmW / $wtmW) * $wtmH;

            $resizedWtm = imagecreatetruecolor((int)$newWtmW, (int)$newWtmH);
            imagealphablending($resizedWtm, false);
            imagesavealpha($resizedWtm, true);
            $transparent = imagecolorallocatealpha($resizedWtm, 255, 255, 255, 127);
            imagefilledrectangle($resizedWtm, 0, 0, (int)$newWtmW, (int)$newWtmH, $transparent);
            
            imagecopyresampled($resizedWtm, $watermark, 0, 0, 0, 0, (int)$newWtmW, (int)$newWtmH, $wtmW, $wtmH);

            // Позиция: Правый нижний угол с отступом 30px
            $destX = $imgW - $newWtmW - 30;
            $destY = $imgH - $newWtmH - 30;

            // Накладываем (Сохраняя прозрачность)
            imagealphablending($image, true);
            imagecopy($image, $resizedWtm, (int)$destX, (int)$destY, 0, 0, (int)$newWtmW, (int)$newWtmH);

            // Сохраняем поверх оригинала
            if ($ext === 'jpg' || $ext === 'jpeg') {
                imagejpeg($image, $filePath, 85);
            } elseif ($ext === 'png') {
                imagepng($image, $filePath);
            } elseif ($ext === 'webp') {
                imagewebp($image, $filePath, 85);
            }

            imagedestroy($image);
            imagedestroy($watermark);
            imagedestroy($resizedWtm);
        }

        return $upload;
    }
}