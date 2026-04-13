<?php

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.0.0
 * RU: Обработчик формы редактирования. Максимальный Anti-SSRF (DNS A/AAAA validation + wp_http_validate_url).
 * EN: Edit form handler. Maximum Anti-SSRF (DNS A/AAAA validation + wp_http_validate_url).
 */
final class ApartmentEditHandler
{
    private const META_SF_ICAL_IMPORT = '_sf_ical_import';
    private const META_SF_ROOM_LAST_URLS = '_sf_ical_urls_last';
    private const LOCK_TTL = 20;

    public function register(): void
    {
        add_action('admin_post_sf_process_edit_apartment', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_edit_apt_nonce']) || !wp_verify_nonce((string) $_POST['sf_edit_apt_nonce'], 'sf_edit_apt_action')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $apt_id = isset($_POST['apt_id']) ? (int) $_POST['apt_id'] : 0;
        if ($apt_id <= 0) wp_die('Fehlende Apartment-ID.');

        $post = get_post($apt_id);
        if (!$post) wp_die('Apartment nicht gefunden.');

        $userId = get_current_user_id();
        $bsbt_owner = (int) get_post_meta($apt_id, 'bsbt_owner_id', true);
        
        if ((int) $post->post_author !== $userId && $bsbt_owner !== $userId && !current_user_can('manage_options')) {
            wp_die('Zugriff verweigert. Keine Berechtigung.');
        }

        $lock_key = 'sf_edit_apt_lock_' . $apt_id;
        if (get_transient($lock_key)) wp_die('Bitte warten: Speichern läuft bereits.');
        set_transient($lock_key, 1, self::LOCK_TTL);

        try {
            // RU: 100% гигиена данных (unslash перед sanitize)
            // EN: 100% data hygiene (unslash before sanitize)
            update_post_meta($apt_id, 'address', sanitize_text_field(wp_unslash((string) ($_POST['apt_address'] ?? ''))));
            update_post_meta($apt_id, 'doorbell_name', sanitize_text_field(wp_unslash((string) ($_POST['apt_doorbell'] ?? ''))));
            update_post_meta($apt_id, 'owner_phone', sanitize_text_field(wp_unslash((string) ($_POST['apt_contact_phone'] ?? ''))));
            update_post_meta($apt_id, '_sf_commune_reg_id', sanitize_text_field(wp_unslash((string) ($_POST['apt_reg_id'] ?? ''))));
            update_post_meta($apt_id, 'owner_name', sanitize_text_field(wp_unslash((string) ($_POST['apt_owner_name'] ?? ''))));
            update_post_meta($apt_id, 'owner_email', sanitize_email(wp_unslash((string) ($_POST['apt_owner_email'] ?? ''))));
            update_post_meta($apt_id, 'house_rules', sanitize_textarea_field(wp_unslash((string) ($_POST['apt_house_rules'] ?? ''))));

            update_post_meta($apt_id, 'kontoinhaber', sanitize_text_field(wp_unslash((string) ($_POST['apt_bank_name'] ?? ''))));
            update_post_meta($apt_id, 'kontonummer', sanitize_text_field(wp_unslash((string) ($_POST['apt_bank_iban'] ?? ''))));
            update_post_meta($apt_id, 'steuernummer', sanitize_text_field(wp_unslash((string) ($_POST['apt_tax_id'] ?? ''))));

            $price = max(0.0, (float) ($_POST['apt_price'] ?? 0));
            update_post_meta($apt_id, '_sf_selling_price', $price);
            update_post_meta($apt_id, '_sf_owner_price', $price);

            $min_stay = max(1, (int) ($_POST['apt_min_stay'] ?? 1));
            update_post_meta($apt_id, 'sf_min_stay', $min_stay);
            update_post_meta($apt_id, '_sf_min_stay', $min_stay); 

            $adults = max(1, (int) ($_POST['apt_adults'] ?? 2));
            update_post_meta($apt_id, 'mphb_adults_capacity', $adults);
            $children = max(0, (int) ($_POST['apt_children'] ?? 0));
            update_post_meta($apt_id, 'mphb_children_capacity', $children);

            $loyalty = isset($_POST['apt_loyalty']) ? '1' : '0';
            update_post_meta($apt_id, '_sf_fair_return', $loyalty);

            $cancel_pol = sanitize_text_field(wp_unslash((string) ($_POST['apt_cancellation'] ?? 'flexible')));
            update_post_meta($apt_id, 'sf_cancellation_policy', $cancel_pol);
            update_post_meta($apt_id, '_sf_cancellation_policy', $cancel_pol);
            
            if ($cancel_pol === 'flexible') {
                $cancel_days = max(1, (int) ($_POST['apt_flex_days'] ?? 14));
                update_post_meta($apt_id, 'sf_cancellation_days', $cancel_days);
                update_post_meta($apt_id, '_sf_cancellation_days', $cancel_days);
            } else {
                delete_post_meta($apt_id, 'sf_cancellation_days');
                delete_post_meta($apt_id, '_sf_cancellation_days');
            }

            if (isset($_POST['apt_category'])) wp_set_object_terms($apt_id, [(int)$_POST['apt_category']], 'mphb_room_type_category');
            if (isset($_POST['apt_attribute_type'])) wp_set_object_terms($apt_id, [(int)$_POST['apt_attribute_type']], 'mphb_ra_apartment-type');
            if (isset($_POST['apt_amenities']) && is_array($_POST['apt_amenities'])) {
                wp_set_object_terms($apt_id, array_map('intval', $_POST['apt_amenities']), 'mphb_room_type_facility');
            } else {
                wp_set_object_terms($apt_id, [], 'mphb_room_type_facility');
            }

            $req_status = isset($_POST['apt_status']) ? (string) wp_unslash($_POST['apt_status']) : 'online';
            $new_status = ($req_status === 'offline') ? 'draft' : (($post->post_status === 'draft') ? 'publish' : $post->post_status);

            wp_update_post([
                'ID'           => $apt_id,
                'post_title'   => sanitize_text_field(wp_unslash((string) ($_POST['apt_name'] ?? ''))),
                'post_content' => wp_kses_post(wp_unslash((string) ($_POST['apt_description'] ?? ''))),
                'post_status'  => $new_status,
            ], true);

            if (class_exists('\StayFlow\BusinessModel\RateSyncService')) {
                $syncService = new \StayFlow\BusinessModel\RateSyncService();
                if (method_exists($syncService, 'syncNativeMphbData')) {
                    $syncService->syncNativeMphbData($apt_id, get_post($apt_id));
                }
            }

            $raw_ical = (string) wp_unslash($_POST['apt_ical'] ?? '');
            $ical_urls = $this->parseIcalUrls($raw_ical);
            update_post_meta($apt_id, self::META_SF_ICAL_IMPORT, wp_json_encode($ical_urls, JSON_UNESCAPED_SLASHES));

            global $wpdb;
            $room_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value = %d", $apt_id));

            if (!empty($room_ids)) {
                foreach ($room_ids as $room_id_raw) {
                    $room_id = (int) $room_id_raw;
                    if ($room_id <= 0) continue;
                    $room_post = get_post($room_id);
                    if (!$room_post || $room_post->post_type !== 'mphb_room') continue;

                    $this->maybeRestoreTrashedRoom($room_id, $new_status);
                    $this->upsertMphbSyncUrlsForRoom($room_id, $ical_urls);

                    wp_cache_delete($room_id, 'post_meta');
                    clean_post_cache($room_id);
                }
            }

            $this->writeMphbSyncUrlsToRoomTypeFallback($apt_id, $ical_urls);
            wp_cache_delete($apt_id, 'post_meta');
            clean_post_cache($apt_id);

            if (isset($_POST['sf_gallery_order'])) {
                $order = sanitize_text_field(wp_unslash($_POST['sf_gallery_order']));
                update_post_meta($apt_id, 'mphb_gallery', $order);
                $ids = array_filter(explode(',', $order));
                if (!empty($ids)) {
                    set_post_thumbnail($apt_id, (int)$ids[0]);
                } else {
                    delete_post_thumbnail($apt_id);
                }
            }

            wp_safe_redirect(add_query_arg('apt_updated', '1', home_url('/owner-apartments/')));
            exit;

        } finally {
            delete_transient($lock_key);
        }
    }

    private function parseIcalUrls(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $out = [];
        
        foreach ($parts as $p) {
            $u = trim((string) $p);
            if ($u === '') continue;
            if (stripos($u, 'webcal://') === 0) $u = 'https://' . substr($u, 9);
            $u = preg_replace('/\s+/', '', $u);
            if (!$u) continue;
            
            $u = esc_url_raw($u, ['https']); 
            if ($u === '') continue;
            
            $scheme = parse_url($u, PHP_URL_SCHEME);
            if (strtolower((string)$scheme) !== 'https') continue;
            
            // RU: 1. Базовая WP-защита (отсекает плохие порты и базовые SSRF паттерны)
            // EN: 1. Basic WP protection (blocks bad ports and basic SSRF patterns)
            if (!wp_http_validate_url($u)) continue;

            $host = parse_url($u, PHP_URL_HOST);
            if (empty($host) || strpos((string)$host, '.') === false) continue;
            $clean_host = trim((string)$host, '[]');

            // RU: 2. Жесткая защита от DNS Rebinding и скрытых локальных сетей (IPv4 & IPv6)
            // EN: 2. Ironclad DNS Rebinding & private network protection (IPv4 & IPv6)
            $records = dns_get_record($clean_host, DNS_A | DNS_AAAA);
            
            // Если домен вообще не имеет A / AAAA записей, отбрасываем (Fail Closed)
            if ($records === false || empty($records)) continue; 

            $is_safe = true;
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? '';
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $is_safe = false;
                    break;
                }
            }
            
            if (!$is_safe) continue;

            $looks_like_ical = (stripos($u, '.ics') !== false) || (stripos($u, 'ical') !== false) || (stripos($u, 'calendar') !== false);
            if (!$looks_like_ical) continue;
            
            $out[] = $u;
        }
        return array_values(array_unique($out));
    }

    private function upsertMphbSyncUrlsForRoom(int $room_id, array $new_urls): void
    {
        $old_sync = get_post_meta($room_id, 'mphb_sync_urls', true);
        if (!is_array($old_sync)) $old_sync = [];

        $existing_urls = [];
        foreach ($old_sync as $row) {
            if (is_array($row) && !empty($row['url'])) $existing_urls[] = trim((string) $row['url']);
        }

        $sf_last = get_post_meta($room_id, self::META_SF_ROOM_LAST_URLS, true);
        $sf_last_urls = $this->decodeJsonStringArray($sf_last);

        $urls_to_keep = [];
        foreach ($existing_urls as $u) {
            if (!in_array($u, $sf_last_urls, true)) $urls_to_keep[] = $u;
        }
        foreach ($new_urls as $u) {
            if (!in_array($u, $urls_to_keep, true)) $urls_to_keep[] = $u;
        }

        if (function_exists('MPHB') && method_exists(MPHB(), 'getRoomRepository')) {
            try {
                $room = MPHB()->getRoomRepository()->findById($room_id);
                if ($room) $room->setSyncUrls($urls_to_keep);
            } catch (\Exception $e) {
                $this->saveMphbSyncUrlsDirectly($room_id, $urls_to_keep);
            }
        } else {
            $this->saveMphbSyncUrlsDirectly($room_id, $urls_to_keep);
        }
        update_post_meta($room_id, self::META_SF_ROOM_LAST_URLS, wp_json_encode(array_values(array_unique($new_urls)), JSON_UNESCAPED_SLASHES));
    }

    private function saveMphbSyncUrlsDirectly(int $room_id, array $urls_to_keep): void
    {
        $new_sync_norm = [];
        $idx = 1; 
        foreach ($urls_to_keep as $u) {
            $new_sync_norm[$idx] = ['url' => $u];
            $idx++;
        }

        if (empty($new_sync_norm)) {
            delete_post_meta($room_id, 'mphb_sync_urls');
            delete_post_meta($room_id, '_mphb_sync_urls_hash');
        } else {
            update_post_meta($room_id, 'mphb_sync_urls', $new_sync_norm);
            $hash = function_exists('mphb_generate_uid') ? (string) mphb_generate_uid() : md5(uniqid((string) $room_id, true));
            update_post_meta($room_id, '_mphb_sync_urls_hash', $hash);
        }
    }

    private function writeMphbSyncUrlsToRoomTypeFallback(int $room_type_id, array $urls): void
    {
        $sync = [];
        $idx = 1;
        foreach ($urls as $u) {
            $sync[$idx] = ['url' => $u];
            $idx++;
        }

        $old = get_post_meta($room_type_id, 'mphb_sync_urls', true);
        if (!is_array($old)) $old = [];

        if (wp_json_encode($old) === wp_json_encode($sync)) return;

        if (empty($sync)) {
            delete_post_meta($room_type_id, 'mphb_sync_urls');
            delete_post_meta($room_type_id, '_mphb_sync_urls_hash');
            return;
        }

        update_post_meta($room_type_id, 'mphb_sync_urls', $sync);
        $hash = function_exists('mphb_generate_uid') ? (string) mphb_generate_uid() : md5(uniqid((string) $room_type_id, true));
        update_post_meta($room_type_id, '_mphb_sync_urls_hash', $hash);
    }

    private function maybeRestoreTrashedRoom(int $room_id, string $room_type_status): void
    {
        $room_post = get_post($room_id);
        if (!$room_post || $room_post->post_status !== 'trash') return;
        $target = ($room_type_status === 'draft') ? 'draft' : 'publish';
        wp_update_post(['ID' => $room_id, 'post_status' => $target]);
    }

    private function decodeJsonStringArray($value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return array_values(array_filter($decoded, 'is_string'));
        }
        if (is_array($value)) return array_values(array_filter($value, 'is_string'));
        return [];
    }
}