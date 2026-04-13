<?php

declare(strict_types=1);

namespace StayFlow\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.7.0
 * RU: Обработчик верификации. Убрана ломкая проверка кэшированного статуса, добавлены debug-коды.
 * EN: Email verification handler. Removed brittle cached status check, added debug codes.
 */
final class VerificationHandler
{
    public function register(): void
    {
        add_action('init', [$this, 'handleVerification']);
    }

    public function handleVerification(): void
    {
        try {
            if (!isset($_GET['sf_verify']) || !isset($_GET['sf_u'])) {
                return;
            }

            $token  = sanitize_text_field(wp_unslash($_GET['sf_verify']));
            $userId = (int)$_GET['sf_u'];

            if ($userId <= 0 || empty($token)) {
                $this->redirectWithError('missing_data');
            }

            clean_user_cache($userId);

            // RU: Если аккаунт уже верифицирован (ботом или прошлым кликом) - перекидываем на логин с успехом
            // EN: If account is already verified (by bot or previous click) - redirect to login with success
            $current_status = (string) get_user_meta($userId, '_sf_account_status', true);
            if ($current_status === 'verified') {
                wp_safe_redirect(home_url('/owner-login/?sf_success=already_verified'));
                exit;
            }

            $savedToken = get_user_meta($userId, '_sf_verify_token', true);
            if (empty($savedToken)) {
                $this->redirectWithError('token_empty');
            }
            
            if ($savedToken !== $token) {
                $this->redirectWithError('token_mismatch');
            }

            // RU: Мягкий TTL-чек (разрешаем 0 для обратной совместимости при лагах кэша)
            // EN: Soft TTL check (allow 0 for backward compatibility during cache lags)
            $tokenTime = (int) get_user_meta($userId, '_sf_verify_token_time', true);
            if ($tokenTime > 0 && (time() - $tokenTime) > 24 * HOUR_IN_SECONDS) {
                delete_user_meta($userId, '_sf_verify_token');
                delete_user_meta($userId, '_sf_verify_token_time');
                $this->redirectWithError('token_expired');
            }

            $user = get_userdata($userId);
            if (!$user instanceof \WP_User) {
                $this->redirectWithError('user_not_found');
            }

            // ==========================================
            // 1. УСПЕШНАЯ ВЕРИФИКАЦИЯ
            // ==========================================
            update_user_meta($userId, '_sf_account_status', 'verified'); 
            delete_user_meta($userId, '_sf_verify_token'); 
            delete_user_meta($userId, '_sf_verify_token_time');

            // ==========================================
            // 2. АВТОМАТИЧЕСКАЯ АВТОРИЗАЦИЯ
            // ==========================================
            if (is_user_logged_in() && get_current_user_id() !== $userId) {
                wp_logout();
            }
            
            wp_clear_auth_cookie(); 
            wp_set_current_user($userId, $user->user_login);
            wp_set_auth_cookie($userId, true); 
            do_action('wp_login', $user->user_login, $user);

            // ==========================================
            // 3. РЕДИРЕКТ В ДАШБОРД
            // ==========================================
            wp_safe_redirect(home_url('/owner-dashboard/'));
            exit;

        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('StayFlow Verification Error: ' . $e->getMessage());
            }
            $this->redirectWithError('exception');
        }
    }

    private function redirectWithError(string $reason = ''): void
    {
        $url = home_url('/owner-login/?sf_error=invalid_token');
        if ($reason !== '') {
            $url = add_query_arg('reason', $reason, $url);
        }
        wp_safe_redirect($url);
        exit;
    }
}