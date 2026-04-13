<?php

declare(strict_types=1);

namespace StayFlow\Api;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.5.0
 * RU: Строгая валидация дат через DateTime, проверка ответа wp_insert_post для атомарности.
 * EN: Strict date validation via DateTime, wp_insert_post response check for atomicity.
 */
final class CalendarApiController
{
    private const NAMESPACE = 'stayflow/v1';
    private const ROUTE_BASE = '/calendar';
    private const OPTION_RULES = 'mphb_booking_rules_custom';
    private const BLOCK_COMMENT = 'StayFlow Owner Block';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE_BASE . '/get', ['methods' => 'GET', 'callback' => [$this, 'getCalendarData'], 'permission_callback' => [$this, 'checkPermission']]);
        register_rest_route(self::NAMESPACE, self::ROUTE_BASE . '/toggle-block', ['methods' => 'POST', 'callback' => [$this, 'toggleBlock'], 'permission_callback' => [$this, 'checkPermission']]);
        register_rest_route(self::NAMESPACE, self::ROUTE_BASE . '/update-price', ['methods' => 'POST', 'callback' => [$this, 'updatePrice'], 'permission_callback' => [$this, 'checkPermission']]);
        register_rest_route(self::NAMESPACE, self::ROUTE_BASE . '/force-sync', ['methods' => 'POST', 'callback' => [$this, 'forceSync'], 'permission_callback' => [$this, 'checkPermission']]);
    }

    // ==========================================
    // Security & Validation
    // ==========================================

    /**
     * RU: Строгая проверка даты для исключения 2026-99-99.
     * EN: Strict date check to exclude invalid dates like 2026-99-99.
     */
    private function isValidDate(string $date): bool {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    private function isUserOwnerOfApt(int $apt_id): bool {
        if (current_user_can('manage_options')) return true;
        
        $post = get_post($apt_id);
        if (!$post || $post->post_type !== 'mphb_room_type') return false;

        $userId = get_current_user_id();
        $bsbt_owner = (int) get_post_meta($apt_id, 'bsbt_owner_id', true);
        
        return ((int) $post->post_author === $userId || $bsbt_owner === $userId);
    }

    public function checkPermission(WP_REST_Request $request): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        
        if (current_user_can('manage_options')) {
            return true;
        }

        $apt_param = $request->get_param('apt_id');
        
        if ($apt_param === 'all') {
            $user = wp_get_current_user();
            return in_array('owner', (array) $user->roles, true);
        }

        return $this->isUserOwnerOfApt((int) $apt_param);
    }

    private function flushMotoPressCache(): void
    {
        global $wpdb;
        $like1 = $wpdb->esc_like('_transient_mphb_') . '%';
        $like2 = $wpdb->esc_like('_transient_timeout_mphb_') . '%';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", 
            $like1, 
            $like2
        ));
        wp_cache_flush();
    }

    // ==========================================
    // Data Providers
    // ==========================================

    public function getCalendarData(WP_REST_Request $request): WP_REST_Response
    {
        $apt_param   = $request->get_param('apt_id');
        $start_param = sanitize_text_field((string)$request->get_param('start_date'));
        $end_param   = sanitize_text_field((string)$request->get_param('end_date'));

        if (!function_exists('MPHB')) return new WP_REST_Response(['error' => 'MPHB not active'], 500);

        try {
            global $wpdb;
            
            if ($start_param && $end_param && $this->isValidDate($start_param) && $this->isValidDate($end_param)) {
                $start_date = new \DateTime($start_param);
                $end_date   = new \DateTime($end_param);
            } else {
                $month = (int) $request->get_param('month') ?: (int) date('n');
                $year  = (int) $request->get_param('year') ?: (int) date('Y');
                $start_date = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
                $end_date   = clone $start_date;
                $end_date->modify('last day of this month');
            }

            $days_diff = $start_date->diff($end_date)->days;
            if ($days_diff > 90) {
                $end_date = clone $start_date;
                $end_date->modify('+90 days');
            }

            if ($apt_param === 'all') {
                $userId = get_current_user_id();
                $apt_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT p.ID 
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
                    WHERE p.post_type = 'mphb_room_type' 
                    AND p.post_status IN ('publish', 'draft', 'pending')
                    AND (p.post_author = %d OR pm.meta_value = %s)
                ", $userId, (string)$userId));

                $apartments = [];
                if (!empty($apt_ids)) {
                    $apartments = get_posts(['post_type' => 'mphb_room_type', 'post__in' => $apt_ids, 'posts_per_page' => -1, 'post_status' => ['publish', 'draft', 'pending']]);
                }

                $result = ['is_all' => true, 'apartments' => []];
                foreach ($apartments as $apt) {
                    $result['apartments'][$apt->ID] = ['title' => $apt->post_title, 'data' => $this->getSingleAptData($apt->ID, clone $start_date, clone $end_date)];
                }
                return new WP_REST_Response($result, 200);
            } else {
                $data = $this->getSingleAptData((int) $apt_param, clone $start_date, clone $end_date);
                $data['is_all'] = false;
                return new WP_REST_Response($data, 200);
            }

        } catch (\Exception $e) { 
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[StayFlow API Error] ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Server error'], 500); 
        }
    }

    private function getSingleAptData(int $apt_id, \DateTime $start_date, \DateTime $end_date): array
    {
        global $wpdb;
        $dates = [];
        
        $room_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value = %s", (string)$apt_id));
        if (empty($room_ids)) return ['dates' => [], 'room_id' => 0];
        $room_id = (int) $room_ids[0];

        $rate_ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id WHERE p.post_type = 'mphb_rate' AND p.post_status = 'publish' AND m.meta_key = 'mphb_room_type_id' AND m.meta_value = %s", (string)$apt_id));

        $season_prices = [];
        foreach ($rate_ids as $r_id) {
            $prices_array = get_post_meta((int)$r_id, 'mphb_season_prices', true);
            if (is_array($prices_array)) {
                foreach ($prices_array as $sp) {
                    $final_price = 0;
                    if (isset($sp['price']['prices'][0])) $final_price = (float) $sp['price']['prices'][0];
                    elseif (isset($sp['price']) && is_numeric($sp['price'])) $final_price = (float) $sp['price'];
                    $season_prices[(int)$sp['season']] = $final_price;
                }
            }
        }

        $parsed_seasons = [];
        if (!empty($season_prices)) {
            $seasons = $wpdb->get_results("SELECT p.ID, m1.meta_value as start_date, m2.meta_value as end_date, m3.meta_value as custom_flag FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'mphb_start_date' LEFT JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = 'mphb_end_date' LEFT JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_sf_custom_price_apt' WHERE p.post_type = 'mphb_season' AND p.post_status = 'publish' ORDER BY p.menu_order ASC, p.ID DESC");
            foreach ($seasons as $s) {
                if (isset($season_prices[(int)$s->ID]) && !empty($s->start_date) && !empty($s->end_date)) {
                    $parsed_seasons[] = ['start' => $s->start_date, 'end' => $s->end_date, 'price' => $season_prices[(int)$s->ID], 'is_custom' => !empty($s->custom_flag)];
                }
            }
        }

        $period = new \DatePeriod($start_date, new \DateInterval('P1D'), (clone $end_date)->modify('+1 day'));
        foreach ($period as $dt) {
            $dt_str = $dt->format('Y-m-d');
            $price = 0; $type = 'Basis';
            foreach ($parsed_seasons as $ps) {
                if ($dt_str >= $ps['start'] && $dt_str <= $ps['end']) {
                    $price = $ps['price']; $type = $ps['is_custom'] ? 'Individuell' : 'Basis'; break;
                }
            }
            $dates[$dt_str] = ['status' => 'free', 'price' => $price, 'price_type' => $type];
        }

        $bookings = \MPHB()->getBookingRepository()->findAll(['rooms' => [$room_id], 'date_from' => $start_date->format('Y-m-d'), 'date_to' => $end_date->format('Y-m-d')]);
        foreach ($bookings as $booking) {
            if (!in_array($booking->getStatus(), ['confirmed', 'pending', 'pending_user', 'pending_payment'], true)) continue;
            $customer = $booking->getCustomer();
            $guest_name = $customer ? $customer->getName() : 'Gast';
            $source_type = 'Stay4Fair'; $platform_name = 'Stay4Fair';

            if ($booking->isImported()) {
                $source_type = 'iCal';
                $prodid = strtolower((string) $booking->getICalProdid());
                $summary = strtolower((string) $booking->getICalSummary());
                if (strpos($prodid, 'airbnb') !== false || strpos($summary, 'airbnb') !== false) $platform_name = 'Airbnb';
                elseif (strpos($prodid, 'booking') !== false || strpos($summary, 'booking') !== false) $platform_name = 'Booking.com';
                else $platform_name = 'iCal (Ext)';
            }

            $book_period = new \DatePeriod($booking->getCheckInDate(), new \DateInterval('P1D'), $booking->getCheckOutDate());
            foreach ($book_period as $dt) {
                $dt_str = $dt->format('Y-m-d');
                if (isset($dates[$dt_str])) {
                    $dates[$dt_str]['status'] = 'booked'; $dates[$dt_str]['booking_id'] = $booking->getId(); $dates[$dt_str]['guest_name'] = $guest_name; $dates[$dt_str]['source_type'] = $source_type; $dates[$dt_str]['platform_name'] = $platform_name;
                }
            }
        }

        $custom_rules = get_option(self::OPTION_RULES, []);
        if (is_array($custom_rules)) {
            foreach ($custom_rules as $rule) {
                if (isset($rule['room_type_id']) && (int)$rule['room_type_id'] !== 0 && (int)$rule['room_type_id'] !== $apt_id) continue; 
                if (!empty($rule['date_from']) && !empty($rule['date_to'])) {
                    $s = new \DateTime($rule['date_from']); $e = new \DateTime($rule['date_to']); $e->modify('+1 day'); 
                    $rule_period = new \DatePeriod($s, new \DateInterval('P1D'), $e);
                    foreach ($rule_period as $dt) {
                        $dt_str = $dt->format('Y-m-d');
                        if (isset($dates[$dt_str])) $dates[$dt_str]['status'] = 'blocked';
                    }
                }
            }
        }
        return ['dates' => $dates, 'room_id' => $room_id];
    }

    // ==========================================
    // Actions
    // ==========================================

    public function toggleBlock(WP_REST_Request $request): WP_REST_Response
    {
        $apt_id = (int) $request->get_param('apt_id'); 
        $start_date = sanitize_text_field((string) $request->get_param('start_date')); 
        $end_date = sanitize_text_field((string) $request->get_param('end_date')); 
        $action = sanitize_text_field((string) $request->get_param('action')); 
        
        if (!$this->isValidDate($start_date) || !$this->isValidDate($end_date) || !in_array($action, ['block', 'unblock'], true)) {
            return new WP_REST_Response(['error' => 'Invalid parameters'], 400);
        }

        // Defence in depth
        if (!$this->isUserOwnerOfApt($apt_id)) {
            return new WP_REST_Response(['error' => 'Permission denied'], 403);
        }

        try {
            $custom_rules = get_option(self::OPTION_RULES, []); 
            if (!is_array($custom_rules)) $custom_rules = [];
            
            if ($action === 'block') {
                $custom_rules[] = ['room_type_id' => (string) $apt_id, 'room_id' => '0', 'date_from' => $start_date, 'date_to' => $end_date, 'restrictions' => ['check-in', 'check-out', 'stay-in'], 'comment' => self::BLOCK_COMMENT];
                update_option(self::OPTION_RULES, array_values($custom_rules));
            } else {
                foreach ($custom_rules as $key => $rule) {
                    if (isset($rule['room_type_id']) && (int)$rule['room_type_id'] === $apt_id && isset($rule['comment']) && $rule['comment'] === self::BLOCK_COMMENT && isset($rule['date_from']) && $rule['date_from'] === $start_date && isset($rule['date_to']) && $rule['date_to'] === $end_date) {
                        unset($custom_rules[$key]);
                    }
                }
                update_option(self::OPTION_RULES, array_values($custom_rules));
            }
            $this->flushMotoPressCache();
            return new WP_REST_Response(['success' => true, 'action' => $action], 200);
        } catch (\Exception $e) { 
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[StayFlow API Error] ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Action failed'], 500); 
        }
    }

    public function updatePrice(WP_REST_Request $request): WP_REST_Response
    {
        $apt_id = (int) $request->get_param('apt_id'); 
        $start_date = sanitize_text_field((string) $request->get_param('start_date')); 
        $end_date = sanitize_text_field((string) $request->get_param('end_date')); 
        $price = (float) $request->get_param('price');
        
        if (!$this->isValidDate($start_date) || !$this->isValidDate($end_date) || $price <= 0) {
            return new WP_REST_Response(['error' => 'Invalid parameters'], 400);
        }

        // Defence in depth
        if (!$this->isUserOwnerOfApt($apt_id)) {
            return new WP_REST_Response(['error' => 'Permission denied'], 403);
        }

        try {
            global $wpdb;
            
            // 1. Находим старые сезоны, но пока НЕ удаляем (Атомарность)
            // 1. Find old seasons, but DO NOT delete yet (Atomicity)
            $old_seasons = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'mphb_start_date' AND m1.meta_value = %s INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = 'mphb_end_date' AND m2.meta_value = %s INNER JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_sf_custom_price_apt' AND m3.meta_value = %s WHERE p.post_type = 'mphb_season'", $start_date, $end_date, (string)$apt_id));
            
            $rate_id = (int) $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id WHERE p.post_type = 'mphb_rate' AND p.post_status = 'publish' AND m.meta_key = 'mphb_room_type_id' AND m.meta_value = %s LIMIT 1", (string)$apt_id));
            
            if (!$rate_id) return new WP_REST_Response(['error' => 'Base Rate not found'], 404);

            // 2. Создаем новый сезон. Обязательная проверка на WP_Error
            // 2. Create new season. Mandatory check for WP_Error
            $season_id = wp_insert_post(['post_type' => 'mphb_season', 'post_title' => 'Individuell: ' . $start_date . ' bis ' . $end_date, 'post_status' => 'publish', 'menu_order' => 0]);

            if (is_wp_error($season_id) || empty($season_id)) {
                return new WP_REST_Response(['error' => 'Failed to create season'], 500);
            }

            $wpdb->update($wpdb->posts, ['menu_order' => 0], ['ID' => $season_id]);
            $likeStr = '%' . $wpdb->esc_like('StayFlow Base Season') . '%';
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET menu_order = 9999 WHERE post_type = 'mphb_season' AND post_title LIKE %s", $likeStr));

            update_post_meta($season_id, 'mphb_start_date', $start_date);
            update_post_meta($season_id, 'mphb_end_date', $end_date);
            update_post_meta($season_id, 'mphb_days', ['0', '1', '2', '3', '4', '5', '6']);
            update_post_meta($season_id, 'mphb_repeat_period', 'none');
            update_post_meta($season_id, 'mphb_repeat_until_date', '');
            update_post_meta($season_id, '_sf_custom_price_apt', $apt_id);
            update_post_meta($season_id, '_sf_custom_price_val', $price);

            // 3. Обновляем массив тарифов
            // 3. Update rates array
            $prices_array = get_post_meta($rate_id, 'mphb_season_prices', true);
            if (!is_array($prices_array)) $prices_array = [];
            foreach ($prices_array as $k => $pa) { if (in_array((string)$pa['season'], $old_seasons)) unset($prices_array[$k]); }
            
            array_unshift($prices_array, ['season' => (string)$season_id, 'price' => [ 'prices' => [ 0 => (string)$price ] ]]);
            $prices_array = array_values($prices_array);
            
            update_post_meta($rate_id, 'mphb_season_prices', $prices_array);
            wp_update_post(['ID' => $rate_id]); 

            // 4. Только если всё прошло успешно, удаляем старые сезоны
            // 4. Only if everything was successful, delete old seasons
            foreach($old_seasons as $os) {
                wp_trash_post((int)$os);
            }

            $this->flushMotoPressCache();

            return new WP_REST_Response(['success' => true], 200);
        } catch (\Exception $e) { 
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[StayFlow API Error] ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Price update failed'], 500); 
        }
    }

    public function forceSync(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => 'Forbidden. Only administrators can force global sync.'], 403);
        }

        if (!function_exists('MPHB') || !method_exists(MPHB(), 'getSyncManager')) {
            return new WP_REST_Response(['error' => 'MPHB Sync unavailable'], 500);
        }
        
        try { 
            MPHB()->getSyncManager()->sync(); 
            $this->flushMotoPressCache(); 
            return new WP_REST_Response(['success' => true], 200); 
        } catch (\Exception $e) { 
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[StayFlow API Error] ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Sync failed'], 500); 
        }
    }
}