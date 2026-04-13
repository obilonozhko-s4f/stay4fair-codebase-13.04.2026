<?php

declare(strict_types=1);

namespace StayFlow\Onboarding;

if (!defined('ABSPATH')) exit;

/**
 * Version: 1.3.0
 * RU: Форма регистрации. 
 * - [UX]: Добавлен HTML5 minlength="8" для пароля и расширен текст ошибки пароля.
 * EN: Registration form.
 * - [UX]: Added HTML5 minlength="8" for password and expanded password error text.
 */
final class OnboardingProvider
{
    // === SECTION: REGISTRATION ===
    public function register(): void
    {
        add_shortcode('sf_owner_onboarding', [$this, 'renderOnboardingForm']);
    }

    // === SECTION: RENDER ===
    public function renderOnboardingForm(): string
    {
        wp_enqueue_style('sf-onboarding-style', plugins_url('assets/css/onboarding.css', dirname(__FILE__, 2)));

        ob_start();

        // ==========================================
        // RU: ЕСЛИ ПОЛЬЗОВАТЕЛЬ УЖЕ АВТОРИЗОВАН
        // EN: IF USER IS ALREADY LOGGED IN
        // ==========================================
        if (is_user_logged_in()) {
            ?>
            <div class="sf-onboarding-wrapper" style="margin-top: 50px;">
                <div class="sf-onboarding-card" style="text-align: center;">
                    <h2 style="color: #082567; margin-bottom: 15px;">Sie sind bereits angemeldet</h2>
                    <p style="color: #64748b; margin-bottom: 30px;">Sie haben bereits ein aktives Konto bei Stay4Fair.</p>
                    <a href="<?php echo esc_url(home_url('/owner-dashboard/')); ?>" class="sf-btn-submit" style="text-decoration: none;">Zum Dashboard</a>
                </div>
            </div>
            <?php
        } else {
            // ==========================================
            // RU: ЕСЛИ ЭТО НОВЫЙ ПОЛЬЗОВАТЕЛЬ (ГОСТЬ)
            // EN: IF IT'S A NEW USER (GUEST)
            // ==========================================
            
            $formData = [
                'owner_type'       => 'private',
                'owner_first_name' => '',
                'owner_last_name'  => '',
                'owner_email'      => '',
                'owner_phone'      => '',
            ];

            $errorHtml = '';
            if (isset($_GET['sf_error'])) {
                $errorCode = sanitize_text_field($_GET['sf_error']);
                $errorMsg = 'Ein unbekannter Fehler ist aufgetreten.';

                switch ($errorCode) {
                    case 'email_exists':
                        $errorMsg = 'Diese E-Mail-Adresse ist bereits registriert. Bitte loggen Sie sich ein oder verwenden Sie eine andere E-Mail.';
                        break;
                    case 'password':
                        // RU: Исправленный текст ошибки (упоминаем длину)
                        // EN: Fixed error text (mentioning length)
                        $errorMsg = 'Das Passwort muss mindestens 8 Zeichen lang sein und die Passwörter müssen übereinstimmen.';
                        break;
                    case 'terms':
                        $errorMsg = 'Bitte akzeptieren Sie die AGB und die Datenschutzerklärung.';
                        break;
                    case 'nonce':
                        $errorMsg = 'Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.';
                        break;
                    case 'bot':
                        $errorMsg = 'Spam-Verdacht. Die Registrierung wurde blockiert.';
                        break;
                    case 'system':
                        $errorMsg = 'Systemfehler bei der Kontoerstellung. Bitte überprüfen Sie Ihre Eingaben oder kontaktieren Sie den Support.';
                        break;
                }

                $errorHtml = '<div style="background-color: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; font-size: 14px; line-height: 1.5;">' . 
                             '<strong>Fehler:</strong> ' . esc_html($errorMsg) . 
                             '</div>';
            }

            if (isset($_GET['sf_sess'])) {
                $sessId = sanitize_text_field($_GET['sf_sess']);
                $savedData = get_transient('sf_ob_sess_' . $sessId);
                if (is_array($savedData)) {
                    $formData = wp_parse_args($savedData, $formData);
                    delete_transient('sf_ob_sess_' . $sessId);
                }
            }
            ?>
            <div class="sf-onboarding-wrapper">
                <div class="sf-onboarding-card">
                    
                    <?php echo $errorHtml; ?>

                    <form id="sf-onboarding-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                        <input type="hidden" name="action" value="sf_process_onboarding">
                        <?php wp_nonce_field('sf_onboarding_action', 'sf_onboarding_nonce'); ?>

                        <div class="sf-form-header">
                            <h2>Als Gastgeber registrieren</h2>
                            <p>Erstelle dein Konto und starte im Owner-Portal</p>
                        </div>
                        
                        <div class="sf-form-grid">
                            
                            <div class="sf-form-group sf-col-2">
                                <label>Ich bin ein: *</label>
                                <select name="owner_type" required>
                                    <option value="private" <?php selected($formData['owner_type'], 'private'); ?>>Privatperson (Einzelvermieter)</option>
                                    <option value="business" <?php selected($formData['owner_type'], 'business'); ?>>Gewerblicher Anbieter / Hotel / Agentur</option>
                                </select>
                            </div>

                            <div class="sf-form-group sf-col-1">
                                <label>Vorname *</label>
                                <input type="text" name="owner_first_name" value="<?php echo esc_attr($formData['owner_first_name']); ?>" required>
                            </div>

                            <div class="sf-form-group sf-col-1">
                                <label>Nachname *</label>
                                <input type="text" name="owner_last_name" value="<?php echo esc_attr($formData['owner_last_name']); ?>" required>
                            </div>

                            <div class="sf-form-group sf-col-2">
                                <label>E-Mail Adresse *</label>
                                <input type="email" name="owner_email" value="<?php echo esc_attr($formData['owner_email']); ?>" required>
                            </div>

                            <div class="sf-form-group sf-col-2">
                                <label>Telefon / WhatsApp *</label>
                                <input type="text" name="owner_phone" placeholder="+49..." value="<?php echo esc_attr($formData['owner_phone']); ?>" required>
                            </div>

                            <div class="sf-form-group sf-col-2" style="position:relative;">
                                <label>Passwort wählen *</label>
                                <input type="password" id="sf_pass" name="owner_pass" minlength="8" autocomplete="new-password" data-lpignore="true" required>
                                <span onclick="toggleSfPass()" class="sf-pass-toggle" title="Passwort anzeigen">👁️</span>
                            </div>

                            <div class="sf-form-group sf-col-2">
                                <label>Passwort wiederholen *</label>
                                <input type="password" id="sf_pass_confirm" name="owner_pass_confirm" minlength="8" autocomplete="new-password" data-lpignore="true" required>
                            </div>

                            <div class="sf-consent-block sf-col-4">
                                <label class="sf-checkbox-label">
                                    <input type="checkbox" name="accept_agb" id="accept_agb" required>
                                    <span>Ich akzeptiere die <a href="https://stay4fair.com/owner-terms-agb/" target="_blank">Allgemeinen Geschäftsbedingungen</a> (AGB) für Vermieter. *</span>
                                </label>

                                <label class="sf-checkbox-label">
                                    <input type="checkbox" name="accept_privacy" id="accept_privacy" required>
                                    <span>Ich habe die <a href="/datenschutz/" target="_blank">Datenschutzerklärung</a> gelesen und bin damit einverstanden. *</span>
                                </label>
                            </div>

                        </div>

                        <div style="display:none !important;">
                            <input type="text" name="sf_confirm_email_field" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="sf-submit-wrapper">
                            <button type="submit" class="sf-btn-submit">
                                Konto erstellen
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function toggleSfPass() {
                var x = document.getElementById("sf_pass");
                var y = document.getElementById("sf_pass_confirm");
                if (x.type === "password") { x.type = "text"; y.type = "text"; }
                else { x.type = "password"; y.type = "password"; }
            }
            </script>
            <?php
        }
        ?>

        <style>
            /* === ONBOARDING FORM CSS === */
            .sf-onboarding-wrapper { font-family: 'Manrope', sans-serif; max-width: 1400px; margin: 0 auto 60px auto; padding: 0 20px; display: flex; justify-content: center; box-sizing: border-box; }
            .sf-onboarding-card { background: #ffffff; border: 1px solid rgba(8, 37, 103, 0.08); border-radius: 20px; padding: 50px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.04); box-sizing: border-box; }
            .sf-form-header { text-align: center; margin-bottom: 40px; }
            .sf-form-header h2 { color: #082567; font-size: 32px; font-weight: 800; margin-bottom: 8px; }
            .sf-form-header p { color: #64748b; font-size: 16px; margin: 0; }

            .sf-form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 25px; margin-bottom: 30px; }
            .sf-col-1 { grid-column: span 1; } .sf-col-2 { grid-column: span 2; } .sf-col-4 { grid-column: span 4; }

            .sf-form-group { display: flex; flex-direction: column; }
            .sf-form-group label { font-size: 14px; font-weight: 700; color: #082567; margin-bottom: 10px; }
            .sf-form-group input, .sf-form-group select { padding: 16px 18px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: inherit; font-size: 15px; color: #1e293b; background: #f8fafc; transition: all 0.3s ease; box-sizing: border-box; width: 100%; }
            .sf-form-group input:focus, .sf-form-group select:focus { outline: none; border-color: #E0B849; background: #ffffff; box-shadow: 0 0 0 4px rgba(224, 184, 73, 0.15); }

            .sf-pass-toggle { position: absolute; right: 18px; bottom: 14px; cursor: pointer; font-size: 20px; display: flex; align-items: center; justify-content: center; user-select: none; z-index: 5; }

            .sf-consent-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 25px; display: flex; flex-direction: column; gap: 15px; }
            .sf-checkbox-label { display: flex; align-items: flex-start; gap: 15px; cursor: pointer; }
            .sf-checkbox-label input { margin-top: 3px; width: 18px; height: 18px; accent-color: #082567; cursor: pointer; flex-shrink: 0; }
            .sf-checkbox-label span { font-size: 14px; line-height: 1.5; color: #475569; }
            .sf-checkbox-label a { color: #082567; font-weight: 700; text-decoration: none; border-bottom: 1px solid rgba(8, 37, 103, 0.3); transition: border-color 0.3s; }
            .sf-checkbox-label a:hover { border-bottom-color: #082567; }

            .sf-submit-wrapper { display: flex; justify-content: center; margin-top: 30px; grid-column: 1 / -1; }

            .sf-btn-submit {
                -webkit-appearance: none !important; appearance: none !important; border: none !important; outline: none !important;
                display: inline-flex !important; align-items: center !important; justify-content: center !important;
                width: 100% !important; max-width: 400px !important; min-height: 55px !important; padding: 15px 30px !important; margin: 0 auto !important;
                font-family: 'Manrope', sans-serif !important; font-size: 18px !important; font-weight: 800 !important; text-decoration: none !important;
                background-color: #E0B849 !important; color: #082567 !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important;
                background-blend-mode: overlay !important; border-radius: 10px !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                position: relative !important; overflow: hidden !important; z-index: 2 !important; cursor: pointer !important; transition: all 0.25s ease !important;
            }
            .sf-btn-submit::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; pointer-events: none !important; }
            .sf-btn-submit:hover { background-color: #082567 !important; color: #E0B849 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.25) 0%, rgba(255,255,255,0.05) 55%, rgba(0,0,0,0.15) 100%) !important; transform: translateY(-2px) !important; }

            @media (max-width: 1024px) {
                .sf-form-grid { grid-template-columns: repeat(2, 1fr); }
                .sf-col-1, .sf-col-2 { grid-column: span 1; }
                .sf-col-4 { grid-column: span 2; }
                .sf-onboarding-card { padding: 40px 30px; }
            }

            @media (max-width: 600px) {
                .sf-form-grid { grid-template-columns: 1fr; gap: 20px; }
                .sf-col-1, .sf-col-2, .sf-col-4 { grid-column: span 1; }
                .sf-onboarding-card { padding: 30px 20px; }
                .sf-btn-submit { max-width: 100% !important; }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}