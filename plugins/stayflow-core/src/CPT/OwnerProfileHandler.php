<?php

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.6.0
 * RU: Обработчик формы профиля владельца. 
 * - [NEW]: Разделение на Pause (Stilllegen) и Delete (DSGVO Löschung).
 * - [SEC]: При Delete посты переназначаются админу (ID 1), а юзер физически удаляется (wp_delete_user).
 * EN: Owner profile form handler. Added Pause and Delete logic. Physical deletion with admin reassignment.
 */
final class OwnerProfileHandler
{
    // ==========================================
    // INIT / ИНИЦИАЛИЗАЦИЯ
    // ==========================================
    public function register(): void
    {
        add_action('admin_post_sf_process_owner_profile', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_profile_csrf']) || !wp_verify_nonce((string)$_POST['sf_profile_csrf'], 'sf_owner_profile_nonce')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $userId = get_current_user_id();
        $action = sanitize_text_field((string)($_POST['profile_action'] ?? ''));

        try {
            if ($action === 'save_profile') {
                $this->saveProfile($userId);
            } elseif ($action === 'save_security') {
                $this->saveSecurity($userId);
            } elseif ($action === 'pause_account') {
                $this->pauseAccount($userId);
            } elseif ($action === 'delete_account') {
                $this->deleteAccount($userId);
            } else {
                wp_die('Unbekannte Aktion.');
            }
        } catch (\Throwable $e) {
            error_log('StayFlow Profile Error: ' . $e->getMessage());
            wp_die('Ein Fehler ist aufgetreten: ' . esc_html($e->getMessage()));
        }
    }

    // ==========================================
    // 1. СОХРАНЕНИЕ БАЗОВЫХ ДАННЫХ / SAVE PROFILE
    // ==========================================
    private function saveProfile(int $userId): void
    {
        if (!empty($_FILES['owner_avatar']['name']) && $_FILES['owner_avatar']['error'] === UPLOAD_ERR_OK) {
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $allowedExts      = ['jpg', 'jpeg', 'png', 'webp'];
            
            $fileCheck = wp_check_filetype_and_ext($_FILES['owner_avatar']['tmp_name'], $_FILES['owner_avatar']['name']);
            
            if (in_array($fileCheck['type'], $allowedMimeTypes, true) && in_array($fileCheck['ext'], $allowedExts, true)) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');

                $attachment_id = media_handle_upload('owner_avatar', 0);

                if (!is_wp_error($attachment_id)) {
                    update_user_meta($userId, 'stayflow_avatar_id', $attachment_id);
                }
            }
        }

        update_user_meta($userId, 'first_name', sanitize_text_field($_POST['first_name'] ?? ''));
        update_user_meta($userId, 'last_name', sanitize_text_field($_POST['last_name'] ?? ''));
        update_user_meta($userId, 'billing_phone', sanitize_text_field($_POST['phone'] ?? ''));
        
        update_user_meta($userId, 'bsbt_alt_phone', sanitize_text_field($_POST['alt_phone'] ?? ''));
        update_user_meta($userId, 'bsbt_account_holder', sanitize_text_field($_POST['bank_name'] ?? ''));
        update_user_meta($userId, 'bsbt_iban', sanitize_text_field($_POST['iban'] ?? ''));
        update_user_meta($userId, 'bsbt_tax_number', sanitize_text_field($_POST['steuernummer'] ?? ''));
        
        update_user_meta($userId, 'billing_address_1', sanitize_text_field($_POST['address'] ?? ''));
        update_user_meta($userId, 'billing_postcode', sanitize_text_field($_POST['postcode'] ?? ''));
        update_user_meta($userId, 'billing_city', sanitize_text_field($_POST['city'] ?? ''));

        if (isset($_POST['company_name'])) update_user_meta($userId, 'sf_company_name', sanitize_text_field($_POST['company_name']));
        if (isset($_POST['vat_id'])) update_user_meta($userId, 'sf_vat_id', sanitize_text_field($_POST['vat_id']));
        if (isset($_POST['company_reg'])) update_user_meta($userId, 'sf_company_reg', sanitize_text_field($_POST['company_reg']));

        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }

    // ==========================================
    // 2. СМЕНА ПАРОЛЯ И E-MAIL / SECURITY DATA
    // ==========================================
    private function saveSecurity(int $userId): void
    {
        $user = get_user_by('id', $userId);
        if (!$user) throw new \Exception('Benutzer nicht gefunden.');

        $currentPass = (string)($_POST['current_pass'] ?? '');
        
        if (!wp_check_password($currentPass, $user->user_pass, $userId)) {
            wp_safe_redirect(add_query_arg('security_error', '1', wp_get_referer()));
            exit;
        }

        $newEmail = sanitize_email($_POST['user_email'] ?? '');
        $newPass  = (string)($_POST['new_pass'] ?? '');
        $updateData = ['ID' => $userId];
        $changed = false;

        if (!empty($newEmail) && $newEmail !== $user->user_email) {
            $existing_user_id = email_exists($newEmail);
            if ($existing_user_id && $existing_user_id !== $userId) {
                wp_safe_redirect(add_query_arg('security_error', 'email_exists', wp_get_referer()));
                exit;
            }
            $updateData['user_email'] = $newEmail;
            $changed = true;
        }

        if (!empty($newPass)) {
            $updateData['user_pass'] = $newPass;
            $changed = true;
        }

        if ($changed) {
            $result = wp_update_user($updateData);
            if (!is_wp_error($result)) {
                clean_user_cache($userId);
                wp_clear_auth_cookie();
                wp_set_authenticated_user_cookie($userId, true);
            }
        }

        wp_safe_redirect(add_query_arg('security_updated', '1', wp_get_referer()));
        exit;
    }

    // ==========================================
    // 3A. ПАУЗА АККАУНТА (STILLLEGEN)
    // ==========================================
    private function pauseAccount(int $userId): void
    {
        global $wpdb;

        // RU: Берем только опубликованные квартиры
        // EN: Fetch only published apartments
        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status = 'publish'
            AND (p.post_author = %d OR pm.meta_value = %s)
        ", $userId, (string)$userId));

        foreach ($apt_ids as $aid) {
            wp_update_post(['ID' => $aid, 'post_status' => 'draft']);
        }

        // Возвращаем юзера в профиль с желтой плашкой, НЕ разлогиниваем
        wp_safe_redirect(add_query_arg('paused', '1', wp_get_referer()));
        exit;
    }

    // ==========================================
    // 3B. ПОЛНОЕ УДАЛЕНИЕ (KOMPLETT LÖSCHEN - DSGVO)
    // ==========================================
    private function deleteAccount(int $userId): void
    {
        if (user_can($userId, 'manage_options')) {
            wp_safe_redirect(add_query_arg('delete_error', 'admin_protection', wp_get_referer()));
            exit;
        }

        global $wpdb;

        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %s)
        ", $userId, (string)$userId));

        // 1. Проверка активных броней
        $hasFutureBookings = false;
        if (!empty($apt_ids) && function_exists('MPHB')) {
            $clean_apt_ids = array_map('intval', $apt_ids);
            $placeholders  = implode(',', array_fill(0, count($clean_apt_ids), '%d'));
            
            $query = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value IN ($placeholders)";
            $room_ids = $wpdb->get_col($wpdb->prepare($query, ...$clean_apt_ids));
            
            if (!empty($room_ids)) {
                $today = current_time('Y-m-d');
                $bookings = \MPHB()->getBookingRepository()->findAll(['rooms' => $room_ids, 'date_from' => $today]);

                foreach ($bookings as $booking) {
                    if (in_array($booking->getStatus(), ['confirmed', 'pending_user', 'pending_payment'], true)) {
                        $hasFutureBookings = true;
                        break;
                    }
                }
            }
        }

        if ($hasFutureBookings) {
            wp_safe_redirect(add_query_arg('delete_error', 'active_bookings', wp_get_referer()));
            exit;
        }

        // 2. Если броней нет - переводим объекты в Draft и стираем мета-привязку к этому юзеру
        foreach ($apt_ids as $aid) {
            wp_update_post(['ID' => $aid, 'post_status' => 'draft']);
            update_post_meta($aid, 'bsbt_owner_id', 1); // Привязываем к Админу (ID 1)
        }

        // 3. Физически удаляем юзера из БД, переназначая все его посты Админу (ID 1)
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        wp_delete_user($userId, 1);

        // 4. Разлогиниваем остаточную сессию и кидаем на главную
        wp_logout();
        wp_safe_redirect(home_url('/?deleted=1'));
        exit;
    }
}