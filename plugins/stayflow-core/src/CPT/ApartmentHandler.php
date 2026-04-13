<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.4.0
 * RU: Обработчик создания. Идеальный hardening загрузки фото (MIME + EXT проверка).
 * EN: Creation handler. Perfect photo upload hardening (MIME + EXT check).
 */
final class ApartmentHandler
{
    public function register(): void
    {
        add_action('admin_post_sf_process_add_apartment', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_add_apt_nonce']) || !wp_verify_nonce($_POST['sf_add_apt_nonce'], 'sf_add_apt_action')) {
            wp_die('Sicherheits-Check fehlgeschlagen.');
        }

        $userId = get_current_user_id();

        // 1. Создание поста "Тип жилья" (Draft)
        $title       = sanitize_text_field($_POST['apt_name']);
        $description = isset($_POST['apt_description']) ? wp_kses_post(wp_unslash($_POST['apt_description'])) : '';

        $postId = wp_insert_post([
            'post_type'    => 'mphb_room_type',
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'draft',
            'post_author'  => $userId
        ]);

        if (is_wp_error($postId)) wp_die('Fehler beim Erstellen des Apartments.');

        // 2. Базовые данные
        update_post_meta($postId, 'bsbt_owner_id', $userId);
        update_post_meta($postId, 'address', sanitize_text_field($_POST['apt_address']));
        update_post_meta($postId, '_sf_commune_reg_id', sanitize_text_field($_POST['apt_reg_id'] ?? ''));
        update_post_meta($postId, 'doorbell_name', sanitize_text_field($_POST['apt_doorbell']));
        update_post_meta($postId, 'owner_phone', sanitize_text_field($_POST['apt_contact_phone']));

        $icalUrl = esc_url_raw($_POST['apt_ical'] ?? '');
        if (!empty($icalUrl)) {
            update_post_meta($postId, '_sf_ical_import', $icalUrl);
            update_post_meta($postId, 'sf_ical_import', $icalUrl);
        }

        // 3. Вместимость 
        update_post_meta($postId, 'mphb_adults_capacity', (int)$_POST['apt_adults']);
        update_post_meta($postId, 'mphb_children_capacity', (int)$_POST['apt_children']);

        // 4. Таксономии
        if (!empty($_POST['apt_category'])) wp_set_post_terms($postId, [(int)$_POST['apt_category']], 'mphb_room_type_category');
        if (!empty($_POST['apt_attribute_type'])) {
            $attrId = (int)$_POST['apt_attribute_type'];
            wp_set_post_terms($postId, [$attrId], 'mphb_ra_apartment-type');
            update_post_meta($postId, 'mphb_attributes', ['apartment-type' => [$attrId]]);
        }
        if (!empty($_POST['apt_amenities']) && is_array($_POST['apt_amenities'])) {
            wp_set_post_terms($postId, array_map('intval', $_POST['apt_amenities']), 'mphb_room_type_facility');
        }

        // 5. Бизнес-модель, Цена и Условия
        update_post_meta($postId, '_bsbt_business_model', 'model_b'); 
        $price = (float)$_POST['apt_price'];
        update_post_meta($postId, 'sf_owner_price', $price); 
        update_post_meta($postId, '_sf_owner_price', $price);
        update_post_meta($postId, '_sf_selling_price', $price);
        
        update_post_meta($postId, 'sf_min_stay', (int)$_POST['apt_min_stay']);
        update_post_meta($postId, '_sf_min_stay', (int)$_POST['apt_min_stay']);
        
        $cancelPolicy = sanitize_text_field($_POST['apt_cancellation']);
        if ($cancelPolicy === 'flexible') {
            update_post_meta($postId, 'sf_cancellation_policy', 'free_cancellation');
            update_post_meta($postId, '_sf_cancellation_policy', 'free_cancellation');
            update_post_meta($postId, 'bsbt_cancel_policy_type', 'standard'); 
            $flexDays = (int)($_POST['apt_flex_days'] ?? 14);
            update_post_meta($postId, 'sf_cancellation_days', $flexDays);
            update_post_meta($postId, '_sf_cancellation_days', $flexDays);
        } else {
            update_post_meta($postId, 'sf_cancellation_policy', 'non_refundable');
            update_post_meta($postId, '_sf_cancellation_policy', 'non_refundable');
            update_post_meta($postId, 'bsbt_cancel_policy_type', 'nonref'); 
        }

        update_post_meta($postId, '_sf_fair_return', isset($_POST['apt_loyalty']) ? 1 : 0);

        // 6. Финансы
        update_post_meta($postId, 'kontoinhaber', sanitize_text_field($_POST['apt_bank_name']));
        update_post_meta($postId, 'kontonummer', sanitize_text_field($_POST['apt_bank_iban']));
        update_post_meta($postId, 'steuernummer', sanitize_text_field($_POST['apt_tax_id']));

        // 7. СОЗДАНИЕ ФИЗИЧЕСКОЙ КВАРТИРЫ И ПРИВЯЗКА iCAL
        $accomId = wp_insert_post([
            'post_type'   => 'mphb_room',
            'post_title'  => $title . ' (Objekt 1)',
            'post_status' => 'publish', 
            'post_author' => $userId
        ]);
        if (!is_wp_error($accomId)) {
            update_post_meta($accomId, 'mphb_room_type_id', $postId);
            update_post_meta($accomId, '_mphb_room_type_id', $postId);
            
            if (!empty($icalUrl)) {
                $sync_urls = [ 1 => [ 'url' => $icalUrl ] ];
                update_post_meta($accomId, 'mphb_sync_urls', $sync_urls);
            }
        }

        // 8. Фотографии
        $this->handlePhotoUploads($postId);

        // 9. Перезапуск хуков
        $post_obj = get_post($postId);
        do_action('save_post', $postId, $post_obj, true);
        do_action('save_post_mphb_room_type', $postId, $post_obj, true);

        // 10. Уведомление
        $this->sendAdminNotification($postId, $title, $userId);

        wp_safe_redirect(add_query_arg('apt_created', '1', home_url('/owner-dashboard/')));
        exit;
    }

    private function handlePhotoUploads(int $postId): void
    {
        if (empty($_FILES['apt_photos']['name'][0])) return;
        if (!defined('BSBT_DISABLE_IMAGE_OPTIMIZER')) define('BSBT_DISABLE_IMAGE_OPTIMIZER', true); 
        if (!defined('SF_APPLY_WATERMARK')) define('SF_APPLY_WATERMARK', true);

        @set_time_limit(300);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $files = $_FILES['apt_photos'];
        $attachment_ids = [];
        $uploaded_count = 0;

        // RU: Двойная проверка: и MIME, и реальное расширение файла.
        // EN: Double check: both MIME and actual file extension.
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $allowedExts      = ['jpg', 'jpeg', 'png', 'webp'];

        foreach ($files['name'] as $key => $value) {
            if ($uploaded_count >= 15) break;
            if ($files['error'][$key] !== UPLOAD_ERR_OK) continue;

            if (!empty($files['name'][$key])) {
                
                $fileCheck = wp_check_filetype_and_ext($files['tmp_name'][$key], $files['name'][$key]);
                
                if (!in_array($fileCheck['type'], $allowedMimeTypes, true) || !in_array($fileCheck['ext'], $allowedExts, true)) {
                    continue; // Skip invalid or malicious files
                }

                $_FILES['sf_custom_upload'] = [
                    'name' => $files['name'][$key], 'type' => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key]
                ];
                
                $attachment_id = media_handle_upload('sf_custom_upload', $postId);
                if (!is_wp_error($attachment_id)) {
                    $attachment_ids[] = $attachment_id;
                    $uploaded_count++;
                    if (count($attachment_ids) === 1) set_post_thumbnail($postId, $attachment_id);
                }
            }
        }
        if (!empty($attachment_ids)) update_post_meta($postId, 'mphb_gallery', implode(',', $attachment_ids));
    }

    private function sendAdminNotification(int $postId, string $title, int $userId): void
    {
        $adminEmail = get_option('admin_email');
        $user       = get_userdata($userId);
        $ownerName  = $user ? $user->display_name : 'Unbekannt';
        $editLink   = admin_url('post.php?post=' . $postId . '&action=edit');
        $subject    = '🔔 Neues Apartment eingereicht: ' . $title;
        $message    = "Gastgeber <strong>{$ownerName}</strong> hat ein neues Apartment (Draft) erstellt: <strong>{$title}</strong>. <a href='{$editLink}'>Prüfen</a>";
        wp_mail($adminEmail, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }
}