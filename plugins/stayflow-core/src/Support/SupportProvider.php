<?php

declare(strict_types=1);

namespace StayFlow\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.2.0
 * RU: Провайдер Центра Поддержки. 
 * - [SEC]: Серверная валидация ID квартир (Source of Truth), role-gate для renderForm(), nocache_headers.
 * - [FIX]: Добавлена проверка post_status != 'trash' в AJAX SQL, честный коммент про Rate Limit TTL.
 * EN: Support Center provider. 
 * - [SEC]: Server-side validation of apartment IDs (Source of Truth), role-gate for renderForm(), nocache_headers.
 * - [FIX]: Added post_status != 'trash' check to AJAX SQL, honest comment about Rate Limit TTL.
 */
final class SupportProvider
{
    public function register(): void
    {
        add_shortcode('sf_owner_support', [$this, 'renderForm']);
        add_action('wp_ajax_sf_owner_support_send', [$this, 'handleEmailRequest']);
    }

    public function renderForm(): string
    {
        if (!is_user_logged_in()) {
            return '<div style="padding: 20px; background: #fef2f2; color: #991b1b; border-radius: 8px;">Bitte loggen Sie sich ein.</div>';
        }

        $user = wp_get_current_user();
        
        // RU: Role-Gate. Блокируем рендер формы для всех, кроме Владельцев и Админов.
        // EN: Role-Gate. Block form render for everyone except Owners and Admins.
        if (!in_array('owner', (array)$user->roles, true) && !current_user_can('manage_options')) {
            return '<div style="padding: 20px; background: #fef2f2; color: #991b1b; border-radius: 8px;">Zugriff verweigert. Nur für Eigentümer.</div>';
        }

        $userId = $user->ID;
        
        global $wpdb;
        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %s)
        ", $userId, (string)$userId));

        $apartments = [];
        if (!empty($apt_ids)) {
            $apartments = get_posts([
                'post_type'      => 'mphb_room_type',
                'post__in'       => array_map('intval', $apt_ids),
                'posts_per_page' => -1,
                'post_status'    => 'any'
            ]);
        }

        $nonce = wp_create_nonce('sf_support_nonce');
        $ajax_url = admin_url('admin-ajax.php');

        // ==========================================================================
        // RU: ЧТЕНИЕ НАСТРОЕК КАНАЛОВ СВЯЗИ
        // EN: READING COMMUNICATION CHANNEL SETTINGS
        // ==========================================================================
        $settings = get_option('stayflow_core_settings', []);
        
        $email_enabled  = isset($settings['support_email_enabled']) ? (int)$settings['support_email_enabled'] : 1;
        $wa_enabled     = isset($settings['support_wa_enabled']) ? (int)$settings['support_wa_enabled'] : 1;
        $tg_enabled     = isset($settings['support_tg_enabled']) ? (int)$settings['support_tg_enabled'] : 0;
        $signal_enabled = isset($settings['support_signal_enabled']) ? (int)$settings['support_signal_enabled'] : 0;

        $wa_phone = !empty($settings['support_phone']) ? preg_replace('/[^0-9]/', '', $settings['support_phone']) : '';
        $tg_id    = !empty($settings['support_tg_id']) ? sanitize_text_field($settings['support_tg_id']) : '';
        $signal_p = !empty($settings['support_signal_phone']) ? sanitize_text_field($settings['support_signal_phone']) : '';
        
        if ($wa_enabled && empty($wa_phone)) {
            $admin_users = get_users(['role' => 'administrator', 'number' => 1]);
            if (!empty($admin_users)) {
                $admin_id = $admin_users[0]->ID;
                $phone = get_user_meta($admin_id, 'bsbt_phone', true) ?: get_user_meta($admin_id, 'billing_phone', true);
                $wa_phone = preg_replace('/[^0-9]/', '', (string)$phone);
            }
        }

        ob_start();
        ?>
        <style>
            .sf-support-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px 0; }
            .sf-support-header { border-bottom: 2px solid #E0B849; padding-bottom: 15px; margin-bottom: 25px; }
            .sf-support-header h2 { color: #082567; margin: 0; font-size: 28px; font-weight: 800; }
            .sf-support-header p { color: #64748b; margin: 10px 0 0 0; font-size: 15px; }

            .sf-support-glass-card { background: rgba(255, 255, 255, 0.8); border: 1px solid #e2e8f0; border-radius: 20px; padding: 30px; box-shadow: 0 10px 30px rgba(8, 37, 103, 0.05); margin-bottom: 30px; }
            
            .sf-apt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; margin: 15px 0 25px 0; }
            .sf-apt-checkbox-item { display: flex; align-items: flex-start; gap: 10px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 12px; border-radius: 10px; cursor: pointer; transition: 0.2s; }
            .sf-apt-checkbox-item:hover { border-color: #E0B849; background: #fdf8ed; }
            .sf-apt-checkbox-item input { margin-top: 3px; }
            .sf-apt-checkbox-item strong { color: #082567; font-size: 14px; display: block; margin-bottom: 2px; }
            .sf-apt-checkbox-item span { color: #64748b; font-size: 11px; }

            .sf-support-textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 12px; padding: 15px; font-family: inherit; font-size: 14px; color: #1e293b; resize: vertical; min-height: 120px; box-sizing: border-box; background: #fff; outline: none; transition: 0.2s; }
            .sf-support-textarea:focus { border-color: #082567; box-shadow: 0 0 0 2px rgba(8, 37, 103, 0.1); }

            .sf-support-actions { display: flex; gap: 15px; margin-top: 25px; flex-wrap: wrap; }
            
            .sf-3d-btn {
                position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                transition: all 0.25s ease !important; cursor: pointer !important; z-index: 2; display: inline-flex;
                align-items: center; justify-content: center; padding: 14px 28px; font-family: 'Segoe UI', Roboto, sans-serif;
                font-weight: 700; font-size: 15px; text-decoration: none !important; -webkit-appearance: none !important; flex: 1; min-width: 200px;
            }
            .sf-3d-btn::before {
                content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; pointer-events: none !important;
            }
            .sf-3d-btn:hover { transform: translateY(-2px) !important; }
            .sf-3d-btn span { position: relative; z-index: 3; display: flex; align-items: center; gap: 8px; }

            .btn-email { background-color: #082567 !important; color: #E0B849 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; }
            .btn-email:hover { background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.1) 100%) !important; }
            
            .btn-wa { background-color: #25D366 !important; color: #ffffff !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; }
            .btn-wa:hover { background-color: #128C7E !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.3) 0%, rgba(0,0,0,0.1) 100%) !important; }

            .btn-tg { background-color: #229ED9 !important; color: #ffffff !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; }
            .btn-tg:hover { background-color: #1C8CBF !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.3) 0%, rgba(0,0,0,0.1) 100%) !important; }

            .btn-signal { background-color: #3a76f0 !important; color: #ffffff !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; }
            .btn-signal:hover { background-color: #2c5bb8 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.3) 0%, rgba(0,0,0,0.1) 100%) !important; }

            @media (max-width: 768px) {
                .sf-support-actions { flex-direction: column; gap: 12px; }
                .sf-support-actions .sf-3d-btn { width: 100%; box-sizing: border-box; }
            }

            .sf-internal-msg-banner { display: none; background: #e0f2fe; color: #0284c7; padding: 10px; border-radius: 8px; text-align: center; margin-top: 20px; font-size: 12px; font-weight: bold; }
        </style>

        <div class="sf-support-container">
            <div class="sf-support-header">
                <h2>Support & Hilfe</h2>
                <p>Haben Sie Fragen oder benötigen Sie Unterstützung? Unser Team ist für Sie da.</p>
            </div>

            <div class="sf-support-glass-card">
                <h3 style="color: #082567; margin: 0 0 5px 0;">1. Worum geht es?</h3>
                <p style="color: #64748b; font-size: 13px; margin: 0 0 15px 0;">Wählen Sie die betreffenden Apartments aus (optional):</p>
                
                <div class="sf-apt-list">
                    <?php if (empty($apartments)): ?>
                        <div style="color: #94a3b8; font-style: italic;">Keine Apartments gefunden.</div>
                    <?php else: ?>
                        <?php foreach ($apartments as $apt): ?>
                            <label class="sf-apt-checkbox-item">
                                <input type="checkbox" class="sf-apt-cb" value="<?php echo esc_attr($apt->post_title); ?>" data-id="<?php echo esc_attr((string)$apt->ID); ?>">
                                <div>
                                    <strong><?php echo esc_html($apt->post_title); ?></strong>
                                    <span>ID: #<?php echo esc_html((string)$apt->ID); ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <h3 style="color: #082567; margin: 25px 0 10px 0;">2. Ihre Nachricht</h3>
                <textarea id="sf-support-msg" class="sf-support-textarea" placeholder="Beschreiben Sie Ihr Anliegen hier..."></textarea>

                <div class="sf-support-actions">
                    <?php if ($email_enabled): ?>
                    <button type="button" class="sf-3d-btn btn-email" id="sf-btn-email">
                        <span>✉️ Senden (E-Mail)</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($wa_enabled && !empty($wa_phone)): ?>
                    <button type="button" class="sf-3d-btn btn-wa" id="sf-btn-wa">
                        <span>💬 WhatsApp</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($tg_enabled && !empty($tg_id)): ?>
                    <button type="button" class="sf-3d-btn btn-tg" id="sf-btn-tg">
                        <span>✈️ Telegram</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($signal_enabled && !empty($signal_p)): ?>
                    <button type="button" class="sf-3d-btn btn-signal" id="sf-btn-signal">
                        <span>🔒 Signal</span>
                    </button>
                    <?php endif; ?>
                </div>
                
                <div id="sf-support-feedback" style="margin-top: 15px; font-weight: bold; text-align: center; display: none;"></div>
                
                <div class="sf-internal-msg-banner" id="sf-future-messenger">
                    Internal Messenger Feature Coming Soon...
                </div>
            </div>

            <div style="text-align: center; margin-top: 20px;">
                <a href="<?php echo home_url('/owner-dashboard/'); ?>" class="sf-3d-btn btn-email" style="display: inline-block; flex: none;">
                    <span>← Zurück zum Dashboard</span>
                </a>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const msgBox = document.getElementById('sf-support-msg');
                const feedback = document.getElementById('sf-support-feedback');
                
                const ownerName = "<?php echo esc_js($user->display_name); ?>";
                const waPhone = "<?php echo esc_js($wa_phone); ?>";
                const tgId = "<?php echo esc_js($tg_id); ?>";
                const signalPhone = "<?php echo esc_js($signal_p); ?>";

                function getSelectedAptsForText() {
                    let apts = [];
                    document.querySelectorAll('.sf-apt-cb:checked').forEach(cb => {
                        apts.push(`${cb.value} (ID: #${cb.getAttribute('data-id')})`);
                    });
                    return apts;
                }

                function getSelectedAptsForAjax() {
                    let apt_ids = [];
                    document.querySelectorAll('.sf-apt-cb:checked').forEach(cb => {
                        apt_ids.push(cb.getAttribute('data-id'));
                    });
                    return apt_ids;
                }

                function buildMessageText() {
                    let apts = getSelectedAptsForText();
                    let msg = msgBox.value.trim();
                    let text = `Hallo Stay4Fair Team!\nIch bin ${ownerName}.\n\n`;
                    if (apts.length > 0) {
                        text += `Meine Frage betrifft folgende Objekte:\n- ${apts.join('\n- ')}\n\n`;
                    }
                    if (msg) text += `Nachricht:\n${msg}`;
                    else text += `Ich habe eine Frage...`;
                    return text;
                }

                const btnWa = document.getElementById('sf-btn-wa');
                if (btnWa) {
                    btnWa.addEventListener('click', function() {
                        let waUrl = `https://wa.me/${waPhone}?text=${encodeURIComponent(buildMessageText())}`;
                        window.open(waUrl, '_blank');
                    });
                }

                const btnTg = document.getElementById('sf-btn-tg');
                if (btnTg) {
                    btnTg.addEventListener('click', function() {
                        let tgUrl = `https://t.me/${tgId}?text=${encodeURIComponent(buildMessageText())}`;
                        window.open(tgUrl, '_blank');
                    });
                }

                const btnSignal = document.getElementById('sf-btn-signal');
                if (btnSignal) {
                    btnSignal.addEventListener('click', function() {
                        let msg = buildMessageText();
                        navigator.clipboard.writeText(msg).then(() => {
                            alert("Ihre Nachricht wurde in die Zwischenablage kopiert! Sie können sie nun in Signal einfügen.");
                            window.open(`https://signal.me/#p/${signalPhone}`, '_blank');
                        }).catch(err => {
                            window.open(`https://signal.me/#p/${signalPhone}`, '_blank');
                        });
                    });
                }

                // ==========================================
                // EMAIL (AJAX) LOGIC
                // ==========================================
                const btnEmail = document.getElementById('sf-btn-email');
                if (btnEmail) {
                    btnEmail.addEventListener('click', function() {
                        let msg = msgBox.value.trim();
                        let apt_ids = getSelectedAptsForAjax();

                        if (!msg && apt_ids.length === 0) {
                            alert('Bitte wählen Sie ein Apartment aus oder schreiben Sie eine Nachricht.');
                            return;
                        }

                        const originalText = btnEmail.innerHTML;
                        btnEmail.innerHTML = '<span>⏳ Wird gesendet...</span>';
                        btnEmail.disabled = true;

                        const formData = new URLSearchParams();
                        formData.append('action', 'sf_owner_support_send');
                        formData.append('_wpnonce', '<?php echo esc_js($nonce); ?>');
                        formData.append('message', msg);
                        formData.append('apartments', JSON.stringify(apt_ids));

                        fetch('<?php echo esc_js($ajax_url); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                msgBox.value = '';
                                document.querySelectorAll('.sf-apt-cb').forEach(cb => cb.checked = false);
                                feedback.style.color = '#25D366';
                                feedback.innerText = 'Ihre Nachricht wurde erfolgreich gesendet!';
                            } else {
                                feedback.style.color = '#ef4444';
                                feedback.innerText = 'Fehler: ' + (data.data?.message || 'Unbekannter Fehler');
                            }
                            feedback.style.display = 'block';
                        })
                        .catch(err => {
                            feedback.style.color = '#ef4444';
                            feedback.innerText = 'Ein Systemfehler ist aufgetreten.';
                            feedback.style.display = 'block';
                        })
                        .finally(() => {
                            btnEmail.innerHTML = originalText;
                            btnEmail.disabled = false;
                            setTimeout(() => { feedback.style.display = 'none'; }, 5000);
                        });
                    });
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }

    // ==========================================================================
    // AJAX HANDLER (EMAIL) - SECURED
    // ==========================================================================
    public function handleEmailRequest(): void
    {
        nocache_headers();
        check_ajax_referer('sf_support_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte loggen Sie sich ein.']);
        }

        $user = wp_get_current_user();
        if (!$user instanceof \WP_User) {
            wp_send_json_error(['message' => 'Benutzer konnte nicht geladen werden.']);
        }

        $userId = $user->ID;

        if (!in_array('owner', (array)$user->roles, true) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Zugriff verweigert. Nur für Eigentümer.']);
        }

        $rate_key = 'sf_support_limit_' . $userId;
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuchen Sie es in einer Stunde erneut.']);
        }
        
        // RU: Базовый Rate Limiting (скользящее окно, продлевается при каждом запросе).
        // EN: Basic Rate Limiting (sliding window, refreshed on each request).
        if ($attempts === 0) {
            set_transient($rate_key, 1, HOUR_IN_SECONDS);
        } else {
            set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);
        }
        
        $message_raw = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        
        $apts_json = isset($_POST['apartments']) ? wp_unslash($_POST['apartments']) : '';
        $apts_raw  = json_decode($apts_json, true);
        $apts_ids  = is_array($apts_raw) ? array_map('intval', $apts_raw) : [];

        $valid_apts_titles = [];

        if (!empty($apts_ids)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($apts_ids), '%d'));
            
            // RU: Строгий Source of Truth с исключением 'trash' (удаленных объектов)
            // EN: Strict Source of Truth excluding 'trash' objects
            $query = "
                SELECT p.ID, p.post_title 
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
                WHERE p.post_type = 'mphb_room_type' 
                AND p.ID IN ($placeholders)
                AND p.post_status != 'trash'
                AND (p.post_author = %d OR pm.meta_value = %s)
            ";
            
            $args = array_merge($apts_ids, [$userId, (string)$userId]);
            $results = $wpdb->get_results($wpdb->prepare($query, ...$args));
            
            foreach ($results as $r) {
                $valid_apts_titles[] = $r->post_title . ' (ID: #' . $r->ID . ')';
            }
        }

        $settings = get_option('stayflow_core_settings', []);
        $to_email = !empty($settings['support_email']) ? sanitize_email($settings['support_email']) : get_option('admin_email');

        $subject = "[Support Ticket] Frage von {$user->display_name}";

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #1d2327; max-width: 600px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;">
            <h2 style="color: #082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px;">Neues Support Ticket</h2>
            
            <p><strong>Vermieter:</strong> <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)</p>
            
            <?php if (!empty($valid_apts_titles)): ?>
                <div style="background: #f8fafc; padding: 15px; border: 1px solid #cbd5e1; border-radius: 8px; margin: 15px 0;">
                    <strong>Betroffene Objekte (Verifiziert):</strong>
                    <ul style="margin-top: 10px; margin-bottom: 0;">
                        <?php foreach ($valid_apts_titles as $title): ?>
                            <li><?php echo esc_html($title); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($message_raw)): ?>
                <div style="background: #fdf8ed; padding: 15px; border: 1px solid #E0B849; border-radius: 8px; margin: 15px 0;">
                    <strong>Nachricht:</strong><br><br>
                    <?php echo nl2br(esc_html($message_raw)); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $email_body = ob_get_clean();

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>'
        ];

        $sent = wp_mail($to_email, $subject, $email_body, $headers);

        if ($sent) {
            wp_send_json_success(['message' => 'Gesendet']);
        } else {
            wp_send_json_error(['message' => 'E-Mail konnte nicht gesendet werden.']);
        }
    }
}