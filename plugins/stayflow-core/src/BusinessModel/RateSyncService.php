<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/BusinessModel/RateSyncService.php
 * Version: 1.4.1
 * RU: Служба синхронизации цены.
 * - [FIX]: Базовый сезон теперь ЖЕСТКО получает menu_order = 9999.
 * - Добавлены обязательные ключи mphb_repeat_period.
 * - [SEC]: Устранен P2 SQL Injection (добавлен $wpdb->prepare для очистки транзиентов).
 */

declare(strict_types=1);

namespace StayFlow\BusinessModel;

if (!defined('ABSPATH')) exit;

final class RateSyncService
{
    public function register(): void
    {
        add_action('save_post_mphb_room_type', [$this, 'syncNativeMphbData'], 100, 2);
    }

    public function syncNativeMphbData(int $propertyId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        $businessModel = get_post_meta($propertyId, '_bsbt_business_model', true);
        if ($businessModel === 'model_a') return; 

        $ownerPrice = get_post_meta($propertyId, '_sf_selling_price', true) ?: get_post_meta($propertyId, '_sf_owner_price', true);
        $minStay    = get_post_meta($propertyId, '_sf_min_stay', true);

        if ($ownerPrice !== '' && is_numeric($ownerPrice)) {
            $this->syncRateAndSeason($propertyId, (float)$ownerPrice);
        }

        if ($minStay !== '' && is_numeric($minStay)) {
            $this->syncBookingRule($propertyId, (int)$minStay);
        }

        add_action('shutdown', [$this, 'hardFlushMotoPressCache']);
    }

    public function hardFlushMotoPressCache(): void
    {
        global $wpdb;
        // RU: БЕЗОПАСНЫЙ ЗАПРОС (P2 Security Fix)
        // EN: SECURE QUERY (P2 Security Fix)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_mphb_%',
            '_transient_timeout_mphb_%'
        ));
        wp_cache_flush();
    }

    private function syncRateAndSeason(int $propertyId, float $price): void
    {
        $seasonId = $this->getOrCreateBaseSeason();

        $query = new \WP_Query(['post_type' => 'mphb_rate', 'meta_key' => '_sf_auto_rate_for_room', 'meta_value' => $propertyId, 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish']);

        if (!empty($query->posts)) {
            $rateId = (int)$query->posts[0];
        } else {
            $rateId = wp_insert_post(['post_type' => 'mphb_rate', 'post_title' => 'Base Rate (StayFlow Auto)', 'post_status' => 'publish']);
            update_post_meta($rateId, '_sf_auto_rate_for_room', $propertyId);
        }

        update_post_meta($rateId, 'mphb_room_type_id', (string)$propertyId);
        update_post_meta($rateId, 'mphb_is_multiprice', '0');

        $existingPrices = get_post_meta($rateId, 'mphb_season_prices', true);
        if (!is_array($existingPrices)) $existingPrices = [];

        $baseFound = false;
        foreach ($existingPrices as $index => $sp) {
            if (isset($sp['season']) && (string)$sp['season'] === (string)$seasonId) {
                $existingPrices[$index]['price'] = ['prices' => [0 => (string)$price]];
                $baseFound = true;
                break;
            }
        }

        if (!$baseFound) {
            $existingPrices[] = ['season' => (string)$seasonId, 'price' => ['prices' => [0 => (string)$price]]];
        }

        update_post_meta($rateId, 'mphb_season_prices', array_values($existingPrices));
        wp_update_post(['ID' => $rateId]);
    }

    private function getOrCreateBaseSeason(): int
    {
        global $wpdb;
        $query = new \WP_Query(['post_type' => 'mphb_season', 'meta_key' => '_sf_is_global_base_season', 'meta_value' => 'yes', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish']);

        if (!empty($query->posts)) {
            $seasonId = (int)$query->posts[0];
            $wpdb->update($wpdb->posts, ['menu_order' => 9999], ['ID' => $seasonId]);
            return $seasonId;
        }

        $seasonId = wp_insert_post(['post_type' => 'mphb_season', 'post_title' => 'StayFlow Base Season (2024-2099)', 'post_status' => 'publish', 'menu_order' => 9999]);
        $wpdb->update($wpdb->posts, ['menu_order' => 9999], ['ID' => $seasonId]); 

        update_post_meta($seasonId, '_sf_is_global_base_season', 'yes');
        update_post_meta($seasonId, 'mphb_start_date', '2024-01-01');
        update_post_meta($seasonId, 'mphb_end_date', '2099-12-31');
        update_post_meta($seasonId, 'mphb_days', ['0', '1', '2', '3', '4', '5', '6']);
        update_post_meta($seasonId, 'mphb_repeat_period', 'none');
        update_post_meta($seasonId, 'mphb_repeat_until_date', '');

        return $seasonId;
    }

    private function syncBookingRule(int $propertyId, int $minStay): void
    {
        $seasonId = $this->getOrCreateBaseSeason();
        $rules = get_option('mphb_min_stay_length', []);
        if (!is_array($rules)) $rules = [];

        foreach ($rules as $index => $rule) {
            if (isset($rule['room_type_ids']) && is_array($rule['room_type_ids'])) {
                $pos = array_search($propertyId, $rule['room_type_ids'], true); 
                if ($pos === false) $pos = array_search((string)$propertyId, $rule['room_type_ids'], true); 
                if ($pos !== false) { unset($rules[$index]['room_type_ids'][$pos]); $rules[$index]['room_type_ids'] = array_values($rules[$index]['room_type_ids']); }
            }
            if (empty($rules[$index]['room_type_ids'])) unset($rules[$index]);
        }

        $rules[] = ['min_stay_length' => $minStay, 'room_type_ids' => [$propertyId], 'season_ids' => [$seasonId]];
        update_option('mphb_min_stay_length', array_values($rules));
    }
}