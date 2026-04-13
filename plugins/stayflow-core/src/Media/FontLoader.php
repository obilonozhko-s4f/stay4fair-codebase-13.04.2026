<?php
/**
 * File: wp-content/plugins/stayflow-core/src/Media/FontLoader.php
 * Version: 1.0.2
 * RU: Финальный щит против Google Fonts. Вырезает запросы даже из хардкода.
 * EN: Final shield against Google Fonts. Strips requests even from hardcoded sources.
 */

declare(strict_types=1);

namespace StayFlow\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class FontLoader 
{
    public static function init(): void 
    {
        // 1. Локальные ассеты
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets'], 1);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets'], 1);

        // 2. Отключаем шрифты Элементора на корню
        add_filter('elementor/frontend/print_google_fonts', '__return_false');
        add_action('wp_enqueue_scripts', function() {
            wp_dequeue_style('google-fonts-1');
        }, 999);

        // 3. Убиваем стандартные очереди
        add_action('wp_print_styles', [self::class, 'dequeueGoogleFonts'], 999);
        add_action('wp_print_footer_scripts', [self::class, 'dequeueGoogleFonts'], 999);

        // 4. УБИЙЦА ХАРДКОДА (Output Buffering)
        // RU: Перехватываем HTML и вырезаем ссылки, если они вшиты в тему вручную.
        add_action('template_redirect', [self::class, 'startBuffer']);
    }

    public static function enqueueAssets(): void 
    {
        $url = plugins_url('assets/css/local-fonts.css', dirname(__DIR__, 2) . '/stayflow-core.php');
        wp_enqueue_style('stayflow-manrope', $url, [], '1.0.2');
    }

    public static function dequeueGoogleFonts(): void 
    {
        global $wp_styles;
        if (!isset($wp_styles) || !is_object($wp_styles)) return;

        foreach ($wp_styles->registered as $handle => $data) {
            if (isset($data->src) && is_string($data->src)) {
                if (strpos($data->src, 'fonts.googleapis.com') !== false || strpos($data->src, 'fonts.gstatic.com') !== false) {
                    wp_dequeue_style($handle);
                }
            }
        }
    }

    /**
     * RU: Запуск буферизации для очистки HTML.
     * EN: Start buffering to clean HTML.
     */
    public static function startBuffer(): void 
    {
        ob_start([self::class, 'cleanHtmlOutput']);
    }

    /**
     * RU: Вырезает теги <link> и <style>, содержащие Google Fonts, из итогового HTML.
     * EN: Strips <link> and <style> tags containing Google Fonts from the final HTML.
     */
    public static function cleanHtmlOutput(string $html): string 
    {
        // Удаляем <link> на шрифты и преконнекты
        $regex = '/<link[^>]+fonts\.(googleapis|gstatic)\.com[^>]+>/i';
        $html = preg_replace($regex, '', $html);

        // Удаляем @import внутри <style>
        $html = preg_replace('/@import\s+url\([^)]*fonts\.googleapis\.com[^)]*\);?/i', '', $html);

        return $html;
    }
}