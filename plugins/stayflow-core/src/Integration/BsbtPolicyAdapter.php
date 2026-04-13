<?php
/**
 * Version: 2.0.0
 * RU: Адаптер для вывода политик отмены. Тексты теперь берутся из Policy Registry (Админка).
 */

declare(strict_types=1);

namespace StayFlow\Integration;

if (!defined('ABSPATH')) {
    exit;
}

final class BsbtPolicyAdapter
{
    public function register(): void
    {
        add_shortcode('bsbt_cancellation_box', [$this, 'renderShortcode']);
        add_action('wp_head', [$this, 'injectStyles']);
    }

    public function renderShortcode(array|string $atts): string
    {
        $attributes = shortcode_atts(['id' => 0], is_array($atts) ? $atts : []);
        $roomId = (int) $attributes['id'];

        if ($roomId === 0) {
            $roomId = get_the_ID();
        }

        if (!$roomId || get_post_type($roomId) !== 'mphb_room_type') {
            return '';
        }

        $policyType = get_post_meta($roomId, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays = (int) get_post_meta($roomId, '_sf_cancellation_days', true);

        $content  = $this->getPolicyText($policyType, $cancelDays);
        $boxClass = 'bsbt-cancel-box-' . esc_attr($policyType);

        $html  = '<div class="bsbt-cancel-box ' . $boxClass . '">';
        $html .= '<h3 class="bsbt-cancel-title">Cancellation Policy</h3>';
        $html .= '<div class="bsbt-cancel-content">' . wp_kses_post($content) . '</div>';
        $html .= '<p class="bsbt-cancel-link-note">';
        $html .= 'Full details can be found in our <a href="' . esc_url(home_url('/cancellation-policy/')) . '" target="_blank">Cancellation Policy</a> ';
        $html .= 'and <a href="' . esc_url(home_url('/terms-and-conditions/')) . '" target="_blank">Terms &amp; Conditions</a>.';
        $html .= '</p>';
        $html .= '</div>';

        return $html;
    }

    private function getPolicyText(string $type, int $days): string
    {
        $registry = get_option('stayflow_registry_policies', []);

        if ($type === 'free_cancellation' && $days > 0) {
            $penaltyDays = $days - 1;
            // RU: Берем текст из базы или дефолтный
            $text = $registry['free_cancellation'] ?? "<ul><li>Free cancellation up to <strong>{days} days before arrival</strong>.</li><li>Penalty from <strong>{penalty_days} days</strong>.</li></ul>";
            
            // RU: Заменяем плейсхолдеры на реальные цифры владельца
            $text = str_replace(['{days}', '{penalty_days}'], [(string)$days, (string)$penaltyDays], $text);
            return $text;
        }

        return $registry['non_refundable'] ?? "<p><strong>Non-Refundable</strong></p>";
    }

    public function injectStyles(): void
    {
        ?>
        <style>
            .bsbt-cancel-box { border-radius: 10px; border: 1px solid rgba(33, 47, 84, 0.10); padding: 18px 20px; margin: 24px 0; background: #ffffff; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            .bsbt-cancel-title { margin: 0 0 8px; font-size: 18px; color: #212F54; font-weight: 700; }
            .bsbt-cancel-content p, .bsbt-cancel-content ul { font-size: 14px; color: #212F54; }
            .bsbt-cancel-box-non_refundable { border-color: rgba(224, 184, 73, 0.6); background: #fffaf2; }
            .bsbt-cancel-box-free_cancellation { border-color: rgba(33, 47, 84, 0.25); }
        </style>
        <?php
    }
}