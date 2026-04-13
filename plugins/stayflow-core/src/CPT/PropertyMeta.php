<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/CPT/PropertyMeta.php
 * Version: 1.6.0
 * RU: Управление мета-полями.
 * - [CRITICAL FIX]: Синхронизация bsbt_owner_id с системным post_author (решает баг невидимости квартир).
 * - [NEW]: Добавлены поля управления политикой отмены (Синхронизация с формой владельца).
 */

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) exit;

final class PropertyMeta
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        add_action('save_post_mphb_room_type', [$this, 'saveMeta'], 5, 2);
    }

    public function addMetaBoxes(): void
    {
        add_meta_box('stayflow_property_settings', 'StayFlow: Настройки Объекта', [$this, 'renderMetabox'], 'mphb_room_type', 'normal', 'high');
    }

    public function renderMetabox(\WP_Post $post): void
    {
        wp_nonce_field('sf_property_meta_action', 'sf_property_meta_nonce');

        $communeId    = get_post_meta($post->ID, '_sf_commune_reg_id', true);
        $minStay      = get_post_meta($post->ID, '_sf_min_stay', true);
        
        // RU: Берем политики отмены
        $cancelPolicy = get_post_meta($post->ID, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays   = get_post_meta($post->ID, '_sf_cancellation_days', true);
        if (!$cancelDays) $cancelDays = 14; // Default if empty

        $ownerPrice   = get_post_meta($post->ID, '_sf_selling_price', true) ?: get_post_meta($post->ID, '_sf_owner_price', true);
        
        global $wpdb;
        $roomId = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value = %d LIMIT 1", $post->ID));
        $icalExport = $roomId ? home_url('/?feed=mphb.ics&accommodation_id=' . $roomId) : 'Zuerst muss eine physische Unterkunft (Room) erstellt werden!';

        $icalImportRaw = get_post_meta($post->ID, '_sf_ical_import', true);
        $icalDisplay = '';
        if (!empty($icalImportRaw)) {
            $decoded = json_decode($icalImportRaw, true);
            if (is_array($decoded)) {
                $icalDisplay = implode("\n", $decoded);
            } else {
                $icalDisplay = $icalImportRaw;
            }
        }

        $businessModel = get_post_meta($post->ID, '_bsbt_business_model', true);
        $isModelA      = ($businessModel === 'model_a');

        ?>
        <style>
            .sf-meta-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .sf-meta-grid { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px; }
            @media (min-width: 768px) { .sf-meta-grid { grid-template-columns: 1fr 1fr; } }
            .sf-meta-group { display: flex; flex-direction: column; width: 100%; box-sizing: border-box; }
            .sf-meta-group label { font-weight: 600; margin-bottom: 6px; color: #1e293b; }
            .sf-meta-group input[type="text"], .sf-meta-group input[type="number"], .sf-meta-group select, .sf-meta-group textarea { width: 100%; padding: 6px 8px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 4px; }
            .sf-meta-title { font-size: 14px; font-weight: 700; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; margin: 24px 0 12px; color: #0f172a; }
            .sf-meta-help { font-size: 12px; color: #64748b; margin-top: 4px; line-height:1.4; }
        </style>

        <div class="sf-meta-container">
            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label>Wohnung ID (Reg.-Nr.):</label>
                    <input type="text" name="sf_commune_reg_id" value="<?php echo esc_attr($communeId); ?>">
                </div>
                <div class="sf-meta-group">
                    <label>Minimum Stay:</label>
                    <input type="number" name="sf_min_stay" value="<?php echo esc_attr($minStay); ?>" min="1">
                </div>
                <div class="sf-meta-group">
                    <label>Базовая цена / Final Price (€):</label>
                    <?php if ($isModelA): ?>
                        <input type="text" value="Заблокировано (Model A)" disabled style="background:#f1f5f9;">
                    <?php else: ?>
                        <input type="text" name="sf_owner_price" value="<?php echo esc_attr($ownerPrice); ?>" required>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sf-meta-title">Stornierungsbedingungen (Cancellation Policy)</div>
            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label>Typ:</label>
                    <select name="sf_cancellation_policy" id="sf_cancel_policy_select">
                        <option value="flexible" <?php selected($cancelPolicy, 'flexible'); ?>>Flexibel (Free Cancellation)</option>
                        <option value="free_cancellation" <?php selected($cancelPolicy, 'free_cancellation'); ?>>Flexibel (Alternative Key)</option>
                        <option value="non_refundable" <?php selected($cancelPolicy, 'non_refundable'); ?>>Nicht erstattbar (Non-Refundable)</option>
                    </select>
                </div>
                <div class="sf-meta-group" id="sf_cancel_days_wrapper">
                    <label>Kostenlos stornierbar bis (Tage vor Anreise):</label>
                    <input type="number" name="sf_cancellation_days" value="<?php echo esc_attr($cancelDays); ?>" min="1">
                </div>
            </div>

            <script>
                // RU: Скрываем дни, если выбрано non_refundable
                document.addEventListener('DOMContentLoaded', function() {
                    var sel = document.getElementById('sf_cancel_policy_select');
                    var wrap = document.getElementById('sf_cancel_days_wrapper');
                    function toggleDays() {
                        wrap.style.display = (sel.value === 'non_refundable') ? 'none' : 'flex';
                    }
                    sel.addEventListener('change', toggleDays);
                    toggleDays();
                });
            </script>
            
            <div class="sf-meta-title">Синхронизация / iCal Sync</div>
            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label>iCal Export (Для Airbnb/Booking):</label>
                    <input type="text" value="<?php echo esc_attr($icalExport); ?>" readonly style="background:#f1f5f9; color:#082567; font-family:monospace;">
                </div>
                <div class="sf-meta-group">
                    <label>iCal Import (Редактируемо):</label>
                    <textarea name="sf_ical_import" rows="3" style="font-family:monospace;"><?php echo esc_textarea($icalDisplay); ?></textarea>
                    <span class="sf-meta-help">Вставьте ссылки, каждую с новой строки.</span>
                </div>
            </div>
        </div>
        <?php
    }

    public function saveMeta(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['sf_property_meta_nonce']) || !wp_verify_nonce($_POST['sf_property_meta_nonce'], 'sf_property_meta_action')) return;
        if (!current_user_can('edit_post', $postId)) return;

        // RU: СИНХРОНИЗАЦИЯ АВТОРА / EN: SYNC AUTHOR
        if (isset($_POST['bsbt_owner_id'])) {
            $new_author_id = (int)$_POST['bsbt_owner_id'];
            if ($new_author_id > 0 && $post->post_author != $new_author_id) {
                remove_action('save_post_mphb_room_type', [$this, 'saveMeta'], 5);
                wp_update_post(['ID' => $postId, 'post_author' => $new_author_id]);
                add_action('save_post_mphb_room_type', [$this, 'saveMeta'], 5, 2);
            }
        }

        if (isset($_POST['sf_commune_reg_id'])) update_post_meta($postId, '_sf_commune_reg_id', sanitize_text_field(wp_unslash($_POST['sf_commune_reg_id'])));
        if (isset($_POST['sf_min_stay'])) update_post_meta($postId, '_sf_min_stay', absint($_POST['sf_min_stay']));

        // RU: СОХРАНЕНИЕ ПОЛИТИК ОТМЕНЫ
        if (isset($_POST['sf_cancellation_policy'])) {
            $policy = sanitize_text_field(wp_unslash($_POST['sf_cancellation_policy']));
            // Нормализуем для базы (переводим flexible в free_cancellation)
            if ($policy === 'flexible') $policy = 'free_cancellation'; 
            update_post_meta($postId, '_sf_cancellation_policy', $policy);
            
            // Также продублируем в старый ключ для совместимости
            update_post_meta($postId, 'cancellation_policy', $policy); 
        }
        
        if (isset($_POST['sf_cancellation_days'])) {
            $days = absint($_POST['sf_cancellation_days']);
            update_post_meta($postId, '_sf_cancellation_days', $days);
            update_post_meta($postId, 'cancellation_days', $days); // Дубликат
        }
        
        if (isset($_POST['sf_ical_import'])) {
            $raw_lines = explode("\n", sanitize_textarea_field(wp_unslash($_POST['sf_ical_import'])));
            $clean_urls = [];
            foreach ($raw_lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $clean_urls[] = esc_url_raw($line);
                }
            }
            update_post_meta($postId, '_sf_ical_import', wp_json_encode($clean_urls, JSON_UNESCAPED_SLASHES));
        }

        $businessModel = get_post_meta($postId, '_bsbt_business_model', true);
        if ($businessModel !== 'model_a') {
            if (isset($_POST['sf_owner_price']) && $_POST['sf_owner_price'] !== '') {
                $priceStr = str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['sf_owner_price']))); 
                $priceFloat = (float)$priceStr;
                update_post_meta($postId, '_sf_owner_price', $priceFloat);
                update_post_meta($postId, '_sf_selling_price', $priceFloat);

                if (class_exists('\StayFlow\BusinessModel\RateSyncService')) {
                    $syncService = new \StayFlow\BusinessModel\RateSyncService();
                    if (method_exists($syncService, 'syncRates')) $syncService->syncRates($postId);
                    elseif (method_exists($syncService, 'syncApartmentRates')) $syncService->syncApartmentRates($postId);
                }

                global $wpdb;
                $rate_id = (int) $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id WHERE p.post_type = 'mphb_rate' AND p.post_status = 'publish' AND m.meta_key = 'mphb_room_type_id' AND m.meta_value = %d LIMIT 1", $postId));
                if ($rate_id) {
                    $custom_seasons = $wpdb->get_results($wpdb->prepare("SELECT p.ID, m_price.meta_value as price FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m_apt ON p.ID = m_apt.post_id AND m_apt.meta_key = '_sf_custom_price_apt' AND m_apt.meta_value = %d INNER JOIN {$wpdb->postmeta} m_price ON p.ID = m_price.post_id AND m_price.meta_key = '_sf_custom_price_val' WHERE p.post_type = 'mphb_season' AND p.post_status = 'publish'", $postId));
                    if (!empty($custom_seasons)) {
                        $prices_array = get_post_meta($rate_id, 'mphb_season_prices', true);
                        if (!is_array($prices_array)) $prices_array = [];
                        foreach ($custom_seasons as $cs) {
                            $exists = false; foreach ($prices_array as $pa) { if ($pa['season'] == $cs->ID) $exists = true; }
                            if (!$exists) $prices_array[] = ['season' => (string)$cs->ID, 'price' => [ 'prices' => [ 0 => (string)$cs->price ] ]];
                        }
                        update_post_meta($rate_id, 'mphb_season_prices', array_values($prices_array));
                        
                        remove_action('save_post_mphb_room_type', [$this, 'saveMeta'], 5);
                        wp_update_post(['ID' => $rate_id]); 
                        add_action('save_post_mphb_room_type', [$this, 'saveMeta'], 5, 2);
                    }
                }
            }
        }
    }
}