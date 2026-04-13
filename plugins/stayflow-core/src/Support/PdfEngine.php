<?php

declare(strict_types=1);

namespace StayFlow\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.2.0
 *
 * RU: Централизованная фабрика PDF-движков.
 * Устраняет SSRF-уязвимость: isRemoteEnabled=false везде.
 * Логотип всегда передаётся как локальный путь (WP_CONTENT_DIR),
 * а не как внешний URL — именно поэтому remote не нужен.
 * * Hardening v1.2.0:
 * - Встроенный fallback-autoloader для вендоров.
 * - Передача параметров pageSize и orientation в mPDF.
 * - Строгая валидация сгенерированного файла (размер > 100 байт).
 * - Усиленная очистка safeFilename (длина, control chars, force .pdf).
 * - Добавлены Security Headers (nosniff, sandbox) для stream/inline.
 *
 * EN: Centralized PDF engine factory.
 * Fixes SSRF: isRemoteEnabled=false everywhere.
 * Logo is always resolved to a local filesystem path
 * (WP_CONTENT_DIR), not an external URL.
 */
final class PdfEngine
{
    /**
     * RU: Относительный путь логотипа внутри wp-content.
     */
    private const LOGO_WP_CONTENT_RELATIVE = '/uploads/2025/12/gorizontal-color-4.png';

    /**
     * RU: Возвращает абсолютный путь к логотипу на диске.
     * Если файл не найден или недоступен для чтения — возвращает пустую строку.
     */
    public static function logoPath(): string
    {
        $path = WP_CONTENT_DIR . self::LOGO_WP_CONTENT_RELATIVE;
        return (is_file($path) && is_readable($path)) ? $path : '';
    }

    /**
     * RU: Определяет доступный движок (mpdf > dompdf > null) с фоллбэк-загрузкой.
     */
    public static function detect(): ?string
    {
        if (class_exists('\Mpdf\Mpdf')) {
            return 'mpdf';
        }
        if (class_exists('\Dompdf\Dompdf')) {
            return 'dompdf';
        }

        return self::bootstrapVendors();
    }

    /**
     * RU: Рендерит HTML в PDF и возвращает байты.
     *
     * @throws \RuntimeException if no PDF engine is available or output is empty.
     */
    public static function render(string $html, string $pageSize = 'A4', string $orientation = 'portrait'): string
    {
        $engine = self::detect();
        $bytes  = '';

        if ($engine === 'mpdf') {
            $bytes = self::renderMpdf($html, $pageSize, $orientation);
        } elseif ($engine === 'dompdf') {
            $bytes = self::renderDompdf($html, $pageSize, $orientation);
        } else {
            throw new \RuntimeException('[PdfEngine] No PDF engine available (mpdf or dompdf required).');
        }

        if ($bytes === '') {
            throw new \RuntimeException('[PdfEngine] Rendered PDF bytes are empty.');
        }

        return $bytes;
    }

    /**
     * RU: Отдаёт PDF браузеру как вложение (download).
     */
    public static function stream(string $html, string $filename, string $pageSize = 'A4', string $orientation = 'portrait'): void
    {
        $bytes = self::render($html, $pageSize, $orientation);
        $safe  = self::safeFilename($filename);

        self::sendHeaders('attachment', $safe, strlen($bytes));
        
        echo $bytes;
        exit;
    }

    /**
     * RU: Отдаёт PDF браузеру для просмотра inline (без скачивания).
     */
    public static function inline(string $html, string $filename, string $pageSize = 'A4', string $orientation = 'portrait'): void
    {
        $bytes = self::render($html, $pageSize, $orientation);
        $safe  = self::safeFilename($filename);

        self::sendHeaders('inline', $safe, strlen($bytes));

        echo $bytes;
        exit;
    }

    /**
     * RU: Сохраняет PDF на диск с жесткой проверкой результата.
     *
     * @throws \RuntimeException on render or write failure, or if file is invalid.
     */
    public static function save(string $html, string $filePath, string $pageSize = 'A4', string $orientation = 'portrait'): void
    {
        $bytes = self::render($html, $pageSize, $orientation);
        $dir   = dirname($filePath);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $written = file_put_contents($filePath, $bytes, LOCK_EX);

        if ($written === false || !is_file($filePath) || filesize($filePath) < 100) {
            // RU: Если файл записался битым - удаляем его
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            throw new \RuntimeException('[PdfEngine] Failed to write a valid PDF to: ' . $filePath);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function renderMpdf(string $html, string $pageSize, string $orientation): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'format'        => $pageSize,
            // 'L' for Landscape, 'P' for Portrait
            'orientation'   => strtoupper(substr($orientation, 0, 1)) === 'L' ? 'L' : 'P',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private static function renderDompdf(string $html, string $pageSize, string $orientation): string
    {
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'         => false,  // SSRF FIX
            'isHtml5ParserEnabled'    => true,
            'defaultMediaType'        => 'print',
            'defaultPaperSize'        => $pageSize,
            'defaultPaperOrientation' => $orientation,
            'chroot'                  => WP_CONTENT_DIR, // Разрешаем только wp-content
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($pageSize, $orientation);
        $dompdf->render();
        return (string) $dompdf->output();
    }

    private static function safeFilename(string $name): string
    {
        // Удаляем path separators, кавычки и control characters
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\"\'<>]/', '_', $name) ?? 'document';
        
        // Ограничиваем длину (чтобы избежать ошибок файловой системы)
        $name = mb_substr($name, 0, 200);

        if (trim($name, '_') === '') {
            $name = 'document';
        }

        // Жестко гарантируем расширение .pdf
        if (strtolower(substr($name, -4)) !== '.pdf') {
            $name .= '.pdf';
        }

        return $name;
    }

    private static function sendHeaders(string $disposition, string $filename, int $length): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: private, max-age=0, must-revalidate');
        
        // Security hardening
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
    }

    /**
     * RU: Пытается найти автолоадер вендоров, если движок еще не загружен.
     */
    private static function bootstrapVendors(): ?string
    {
        // Ищем mPDF
        $mpdfCandidates = [
            WP_PLUGIN_DIR . '/motopress-hotel-booking-pdf-invoices/vendor/autoload.php',
            WP_PLUGIN_DIR . '/hotel-booking-pdf-invoices/vendor/autoload.php',
        ];

        foreach ($mpdfCandidates as $autoload) {
            if (is_file($autoload) && is_readable($autoload)) {
                require_once $autoload;
                if (class_exists('\Mpdf\Mpdf')) {
                    return 'mpdf';
                }
            }
        }

        // Ищем Dompdf
        $dompdfAutoload = WP_PLUGIN_DIR . '/mphb-invoices/vendors/dompdf/autoload.inc.php';
        if (is_file($dompdfAutoload) && is_readable($dompdfAutoload)) {
            require_once $dompdfAutoload;
            if (class_exists('\Dompdf\Dompdf')) {
                return 'dompdf';
            }
        }

        return null;
    }
}