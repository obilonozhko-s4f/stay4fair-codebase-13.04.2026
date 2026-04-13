<?php
/**
 * Plugin Name: BSBT – Owner Login & Portal Access
 * Description: Version 2.9.3 – Security hardening (Anti-Enumeration + safe username/email handling + Audit Log).
 * Version: 2.9.3
 * RU: Форма логина теперь умеет выводить Debug-коды при сбоях верификации.
 * - [SEC]: Защита от Username Enumeration (одинаковые ошибки + Anti-Timing).
 * - [SEC]: Безопасная обработка пароля (wp_unslash без нарушения спецсимволов).
 * - [FIX]: Поле логина больше не проходит через sanitize_user, чтобы не ломать вход по email.
 * - [SEC]: Интеграция AuditLogger для фиксации неудачных попыток входа.
 * EN: Login form now displays Debug codes on verification failures.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'logout-button.php';

final class BSBT_Owner_Login_System {

    private $navy = '#082567';
    private $gold = '#E0B849';

    public function __construct() {
        add_shortcode( 'bsbt_owner_login', [ $this, 'render_login_form' ] );
        add_action( 'wp_head', [ $this, 'inject_global_styles' ] );
        add_filter( 'gettext', [ $this, 'translate_wc_texts' ], 999, 3 );
        add_action( 'init', [ $this, 'handle_owner_logout' ] );
        add_action( 'woocommerce_lostpassword_form', [ $this, 'add_back_to_login_link' ] );
    }

    public function handle_owner_logout() {
        if ( isset($_GET['action']) && $_GET['action'] === 'owner_logout' ) {
            $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';

            if ( ! wp_verify_nonce( $nonce, 'bsbt_owner_logout' ) ) {
                wp_die('Security check failed.');
            }

            wp_logout();
            wp_safe_redirect( site_url('/owner-login/?logout=success') );
            exit;
        }
    }

    public function translate_wc_texts( $translated, $text, $domain ) {
        if ( $domain === 'woocommerce' || $domain === 'default' ) {
            $maps = [
                'Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.' =>
                    'Passwort vergessen? Bitte gib deinen Benutzernamen oder deine E-Mail-Adresse ein.',
                'Username or email' => 'Benutzername oder E-Mail',
                'Reset password' => 'Passwort zurücksetzen'
            ];
            if ( isset($maps[$text]) ) {
                return $maps[$text];
            }
        }
        return $translated;
    }

    public function add_back_to_login_link() {
        echo '<div class="bsbt-back-to-login">
                <a href="' . esc_url(site_url('/owner-login/')) . '">← Zurück zum Login</a>
              </div>';
    }

    public function render_login_form() {

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $is_owner = in_array('owner', (array) $user->roles, true);
            $is_admin = in_array('administrator', (array) $user->roles, true) || $user->has_cap('manage_options');

            if ( $is_owner || $is_admin ) {
                wp_safe_redirect( site_url('/owner-dashboard/') );
                exit;
            }

            return '<div class="bsbt-error-box">Zugriff verweigert. Kein Owner-Konto.</div>';
        }

        $error = '';
        $success_msg = '';

        if ( isset($_GET['sf_error']) && $_GET['sf_error'] === 'invalid_token' ) {
            $reasonCode = isset($_GET['reason']) ? ' (Code: ' . sanitize_text_field((string) $_GET['reason']) . ')' : '';
            $error = 'Dieser Aktivierungslink ist ungültig oder bereits abgelaufen. Bitte fordern Sie einen neuen an oder kontaktieren Sie den Support.' . $reasonCode;
        } elseif ( isset($_GET['logout']) && $_GET['logout'] === 'success' ) {
            $success_msg = 'Abgemeldet.';
        } elseif ( isset($_GET['sf_success']) && $_GET['sf_success'] === 'already_verified' ) {
            $success_msg = 'Ihr Konto ist bereits aktiviert. Sie können sich nun einloggen.';
        }

        $redirect_to = isset($_GET['redirect_to']) ? wp_validate_redirect(wp_unslash($_GET['redirect_to']), '') : '';
        if ( ! $redirect_to ) {
            $redirect_to = site_url('/owner-dashboard/');
        }

        if (
            isset($_SERVER['REQUEST_METHOD']) &&
            strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST' &&
            isset($_POST['bsbt_login_submit'])
        ) {
            if (
                ! isset($_POST['bsbt_owner_login_nonce']) ||
                ! wp_verify_nonce($_POST['bsbt_owner_login_nonce'], 'bsbt_owner_login_action')
            ) {
                $error = 'Security check failed. Bitte laden Sie die Seite neu.';
            } else {
                $post_redirect = isset($_POST['redirect_to']) ? wp_validate_redirect(wp_unslash($_POST['redirect_to']), '') : '';
                if ( $post_redirect ) {
                    $redirect_to = $post_redirect;
                }

                // RU: Поле допускает username ИЛИ email, поэтому не используем sanitize_user().
                // EN: The field accepts username OR email, so do not use sanitize_user().
                $login_input = trim((string) wp_unslash($_POST['log']));
                $password    = (string) wp_unslash($_POST['pwd']);

                $creds = [
                    'user_login'    => $login_input,
                    'user_password' => $password,
                    'remember'      => isset($_POST['rememberme']),
                ];

                $user = wp_signon($creds, is_ssl());

                if ( is_wp_error($user) ) {
                    // RU: Базовый anti-enumeration / anti-timing hardening.
                    // EN: Basic anti-enumeration / anti-timing hardening.
                    usleep(random_int(200000, 500000));
                    
                    // RU: Логируем неудачную попытку входа в иммунный аудит
                    if ( class_exists('\StayFlow\Support\AuditLogger') ) {
                        \StayFlow\Support\AuditLogger::financial('failed_login_attempt', [
                            'attempted_login' => $login_input
                        ]);
                    }

                    $error = 'Falsche Zugangsdaten.';
                } else {
                    $is_owner = in_array('owner', (array) $user->roles, true);
                    $is_admin = in_array('administrator', (array) $user->roles, true) || $user->has_cap('manage_options');
                    $status   = get_user_meta($user->ID, '_sf_account_status', true);

                    if ( ! $is_owner && ! $is_admin ) {
                        wp_logout();
                        $error = 'Zugriff verweigert. Kein Owner-Konto.';
                    } elseif ( $is_owner && ! $is_admin && $status === 'pending_verification' ) {
                        wp_logout();
                        $error = 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse (Prüfen Sie Ihren Posteingang/Spam-Ordner).';
                    } else {
                        wp_safe_redirect( $redirect_to );
                        exit;
                    }
                }
            }
        }

        ob_start(); ?>
        <div class="bsbt-login-page-wrapper">
            <div class="bsbt-login-card">
                <div class="bsbt-login-header" style="text-align: center; margin-bottom: 30px;">
                    <h2 style="color: <?= $this->navy ?>; font-size: 28px; font-weight: 800; margin: 0;">Eigentümer Login</h2>
                    <p style="color: #64748b; font-size: 14px;">Partner-Portal Stay4Fair</p>
                </div>

                <?php if ( $error ) : ?>
                    <div class="bsbt-error-box"><?= esc_html($error) ?></div>
                <?php endif; ?>

                <?php if ( $success_msg ) : ?>
                    <div class="bsbt-success-box"><?= esc_html($success_msg) ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field('bsbt_owner_login_action', 'bsbt_owner_login_nonce'); ?>
                    <input type="hidden" name="redirect_to" value="<?= esc_attr($redirect_to) ?>">

                    <div class="bsbt-input-group">
                        <label>Benutzername oder E-Mail</label>
                        <input type="text" name="log" required>
                    </div>

                    <div class="bsbt-input-group">
                        <label>Passwort</label>
                        <input type="password" name="pwd" required>
                    </div>

                    <button type="submit" name="bsbt_login_submit" class="bsbt-cta-button">Einloggen</button>

                    <div class="bsbt-form-footer">
                        <a href="<?= esc_url( wp_lostpassword_url() ) ?>">Passwort vergessen?</a>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function inject_global_styles() {
        ?>
        <style>
        .bsbt-login-page-wrapper { display:flex; align-items:center; justify-content:center; min-height:80vh; padding:40px 20px; background:#fff; }
        .bsbt-login-card { width:100%; max-width:440px; padding:40px; border-radius:20px; background:#fff; box-shadow:0 20px 50px rgba(0,0,0,0.1); border:1px solid #f0f0f0; }
        .bsbt-input-group { margin-bottom:20px; }
        .bsbt-input-group label { display:block; font-weight:600; margin-bottom:8px; color: <?= $this->navy ?>; }
        .bsbt-input-group input { width:100%; height:52px; border-radius:10px; border:1px solid #ddd; padding:0 15px; }

        .bsbt-cta-button {
            position:relative; display:flex; align-items:center; justify-content:center; width:100%; height:60px;
            border-radius:12px; border:none; cursor:pointer; font-weight:800; text-transform:uppercase;
            background-color: <?= $this->navy ?>; color: <?= $this->gold ?>;
            background-image: linear-gradient(180deg, rgba(255,255,255,0.25) 0%, rgba(0,0,0,0.2) 100%);
            box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30);
            transition:all 0.25s ease;
        }
        .bsbt-cta-button:hover { background-color: <?= $this->gold ?>; color: <?= $this->navy ?>; transform:translateY(-2px); }

        .bsbt-form-footer { margin-top: 25px; text-align: center; width: 100%; }
        .bsbt-form-footer a { color: #94a3b8; font-size: 13px; font-weight: 600; text-decoration: none; transition: color 0.2s; }
        .bsbt-form-footer a:hover { color: <?= $this->navy ?>; text-decoration: underline; }

        .bsbt-error-box, .bsbt-success-box { padding:15px; border-radius:10px; margin-bottom:20px; text-align:center; font-size:14px; line-height:1.4; }
        .bsbt-error-box { background:#fef2f2; color:#b91c1c; border-left:4px solid #ef4444; }
        .bsbt-success-box { background:#f0fdf4; color:#166534; border-left:4px solid #22c55e; }

        @media (max-width:480px){ .bsbt-login-card { padding:28px; } .bsbt-cta-button { height:54px; font-size:14px; } }
        </style>
        <?php
    }
}

new BSBT_Owner_Login_System();