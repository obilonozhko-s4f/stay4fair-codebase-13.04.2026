<?php

declare(strict_types=1);

namespace StayFlow\Onboarding;

use StayFlow\Settings\SettingsStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.2.0
 * RU: Обработчик регистрации с фирменным HTML-шаблоном письма и строгой защитой.
 * EN: Registration handler with branded HTML email template and strict protection.
 */
final class OnboardingHandler
{
    private SettingsStore $settings;

    public function __construct(SettingsStore $settings)
    {
        $this->settings = $settings;
    }

    // ==========================================
    // РЕГИСТРАЦИЯ ХУКОВ / REGISTER HOOKS
    // ==========================================
    public function register(): void
    {
        add_action('admin_post_nopriv_sf_process_onboarding', [$this, 'handleForm']);
        add_action('admin_post_sf_process_onboarding', [$this, 'handleForm']);
    }

    private function redirectWithError(string $errorCode): void
    {
        $url = wp_get_referer() ?: home_url(); 
        $url = remove_query_arg(['sf_error', 'sf_sess'], $url);

        $sessionId = wp_generate_password(12, false);
        $safeData = [
            'owner_type'       => sanitize_text_field($_POST['owner_type'] ?? 'private'),
            'owner_first_name' => sanitize_text_field($_POST['owner_first_name'] ?? ''),
            'owner_last_name'  => sanitize_text_field($_POST['owner_last_name'] ?? ''),
            'owner_email'      => sanitize_email($_POST['owner_email'] ?? ''),
            'owner_phone'      => sanitize_text_field($_POST['owner_phone'] ?? ''),
        ];
        set_transient('sf_ob_sess_' . $sessionId, $safeData, 300);

        wp_safe_redirect(add_query_arg(['sf_error' => $errorCode, 'sf_sess' => $sessionId], $url));
        exit;
    }

    // ==========================================
    // ОБРАБОТКА ФОРМЫ / FORM PROCESSING
    // ==========================================
    public function handleForm(): void
    {
        try {
            if (!isset($_POST['sf_onboarding_nonce']) || !wp_verify_nonce($_POST['sf_onboarding_nonce'], 'sf_onboarding_action')) {
                $this->redirectWithError('nonce');
            }

            if (!empty($_POST['sf_confirm_email_field'])) {
                $this->redirectWithError('bot');
            }

            if (empty($_POST['accept_agb']) || empty($_POST['accept_privacy'])) {
                $this->redirectWithError('terms');
            }

            $email     = sanitize_email($_POST['owner_email'] ?? '');
            $pass      = $_POST['owner_pass'] ?? '';
            $confirm   = $_POST['owner_pass_confirm'] ?? '';
            $phone     = sanitize_text_field($_POST['owner_phone'] ?? '');
            $firstName = sanitize_text_field($_POST['owner_first_name'] ?? '');
            $lastName  = sanitize_text_field($_POST['owner_last_name'] ?? '');

            if ($pass !== $confirm) {
                $this->redirectWithError('password');
            }

            // RU: Двойная проверка на существование (предотвращает создание дубликатов)
            // EN: Double check for existence (prevents creating duplicates)
            if (email_exists($email) || username_exists($email)) {
                $this->redirectWithError('email_exists');
            }

            $userId = wp_create_user($email, $pass, $email);
            if (is_wp_error($userId)) {
                $this->redirectWithError('system');
            }

            wp_update_user([
                'ID'           => $userId,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'role'         => 'owner',
                'display_name' => $firstName . ' ' . $lastName
            ]);

            update_user_meta($userId, '_sf_owner_type', sanitize_text_field($_POST['owner_type'] ?? 'private'));
            update_user_meta($userId, 'bsbt_phone', $phone);
            update_user_meta($userId, '_sf_account_status', 'pending_verification');
            update_user_meta($userId, '_sf_consent_agb_date', current_time('mysql'));
            update_user_meta($userId, '_sf_consent_privacy_date', current_time('mysql'));
            
            $token = wp_generate_password(20, false);
            update_user_meta($userId, '_sf_verify_token', $token);

            $this->sendEmail($userId, $email, $firstName, $token);

            wp_redirect(home_url('/registrierung-erfolgreich/'));
            exit;

        } catch (\Throwable $e) {
            error_log('StayFlow Onboarding Error: ' . $e->getMessage());
            $this->redirectWithError('system');
        }
    }

    // ==========================================
    // ОТПРАВКА ПИСЬМА / SENDING EMAIL
    // ==========================================
    /**
     * RU: Отправка красивого HTML письма с логотипом и кнопкой.
     * EN: Sending a beautiful HTML email with a logo and a button.
     */
    private function sendEmail(int $userId, string $email, string $name, string $token): void
    {
        $subject = 'Willkommen bei Stay4Fair – Bitte bestätigen Sie Ihre E-Mail';
        $verifyLink = add_query_arg([
            'sf_verify' => $token,
            'sf_u'      => $userId
        ], home_url('/owner-login/'));

        $logoUrl = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>E-Mail Bestätigung</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 20px 0;">
                <tr>
                    <td align="center">
                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                            
                            <tr>
                                <td style="padding: 30px; background-color: #ffffff; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <img src="<?php echo esc_url($logoUrl); ?>" alt="Stay4Fair.com" width="220" border="0" style="display: block; margin: 0 auto; outline: none; text-decoration: none;">
                                </td>
                            </tr>

                            <tr>
                                <td style="padding: 40px 30px; color: #1d2327;">
                                    <h1 style="color: #082567; margin: 0 0 20px 0; font-size: 24px; font-weight: bold; text-align: center;">Konto aktivieren</h1>
                                    
                                    <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6;">Hallo <?php echo esc_html($name); ?>,</p>
                                    <p style="margin: 0 0 30px 0; font-size: 16px; line-height: 1.6;">vielen Dank für Ihre Registrierung bei Stay4Fair! Bitte bestätigen Sie Ihre E-Mail-Adresse, um die Einrichtung Ihres Kontos abzuschließen und Ihr erstes Apartment hinzuzufügen.</p>

                                    <p style="text-align: center; margin: 40px 0 0 0;">
                                        <a href="<?php echo esc_url($verifyLink); ?>" style="display: inline-block; background-color: #082567; color: #E0B849; text-decoration: none; padding: 16px 32px; border-radius: 10px; font-weight: bold; font-size: 16px; box-shadow: 0 6px 0 #03143c, 0 8px 15px rgba(0,0,0,0.3);">E-Mail bestätigen</a>
                                    </p>

                                    <div style="font-size: 12px; color: #64748b; text-align: center; margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                                        Falls der Button nicht funktioniert, kopieren Sie diesen Link und fügen Sie ihn in Ihren Browser ein:<br><br>
                                        <a href="<?php echo esc_url($verifyLink); ?>" style="color: #082567; word-break: break-all;"><?php echo esc_url($verifyLink); ?></a>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <td style="padding: 30px; background-color: #082567; color: #ffffff; border-radius: 0 0 16px 16px; text-align: center;">
                                    <p style="margin: 0 0 15px 0; font-size: 14px; font-weight: bold;">Haben Sie Fragen?</p>
                                    <p style="margin: 0 0 8px 0; font-size: 13px;">WhatsApp / Tel: <a href="tel:+4917624615269" style="color: #E0B849; text-decoration: none;">+49 176 24615269</a></p>
                                    <p style="margin: 0; font-size: 13px;">E-Mail: <a href="mailto:business@stay4fair.com" style="color: #E0B849; text-decoration: none;">business@stay4fair.com</a></p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        $message = ob_get_clean();
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        wp_mail($email, $subject, $message, $headers);
    }
}