<?php

declare(strict_types=1);

namespace StayFlow\Legal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.5.1 (White Logo Email Edition)
 * RU: Менеджер юридических документов.
 * Хардкод текстов полностью УБРАН. Добавлена вкладка Cancellation Policy.
 * Обновлен шаблон email-уведомлений: теперь это красивая HTML-письмо в стиле бренда.
 * [FIX] Логотип заменен на белый, чтобы не сливался с фоном хедера.
 * * EN: Legal documents manager.
 * Text hardcoding is completely REMOVED. Added Cancellation Policy tab.
 * Updated email notification template: now a beautiful branded HTML email.
 * [FIX] Logo replaced with white version to prevent merging with header background.
 */
final class LegalManager
{
    private const OPTION_KEY = 'stayflow_legal_documents';
    private const CRON_HOOK  = 'sf_batch_email_owners_hook';
    private const BATCH_SIZE = 15;

    public function register(): void
    {
        add_shortcode('stayflow_legal_doc', [$this, 'renderShortcode']);
        add_action('admin_post_sf_save_legal_docs', [$this, 'handleSave']);
        add_action(self::CRON_HOOK, [$this, 'processEmailBatch']);
    }

    public function renderShortcode(array|string $atts): string
    {
        $attributes = shortcode_atts(['type' => 'guest_policies'], is_array($atts) ? $atts : []);
        $docType = sanitize_text_field($attributes['type']);

        $docs = get_option(self::OPTION_KEY, []);
        
        $contentEn = !empty($docs[$docType]['en']) ? $docs[$docType]['en'] : '';
        $contentDe = !empty($docs[$docType]['de']) ? $docs[$docType]['de'] : '';
        $lastUpdated = $docs[$docType]['last_updated'] ?? date('F Y');

        if (empty($contentEn) && empty($contentDe)) {
            return '<p>Document not found or empty. Please configure it in the WP Admin Panel -> Legal Documents Registry.</p>';
        }

        ob_start();
        ?>
        <div class="sf-legal-wrapper">
            <div class="lang-switcher" style="margin: 0 auto 30px auto; text-align:center;">
                <a href="#" class="lang-btn" data-lang="en">English</a> |
                <a href="#" class="lang-btn" data-lang="de">Deutsch</a>
            </div>

            <div class="lang-section terms-block" data-lang="en">
                <p class="sf-last-updated"><strong>Last updated:</strong> <?php echo esc_html(date('F Y', strtotime($lastUpdated))); ?></p>
                <?php echo wp_kses_post(wpautop($contentEn)); ?>
            </div>

            <div class="lang-section terms-block" data-lang="de">
                <p class="sf-last-updated"><strong>Stand:</strong> <?php echo esc_html(date('F Y', strtotime($lastUpdated))); ?></p>
                <?php echo wp_kses_post(wpautop($contentDe)); ?>
            </div>
        </div>

        <style>
            .lang-switcher a { color:#212F54; font-weight:600; text-decoration:none; font-size:15px; cursor:pointer; padding: 0 8px;}
            .lang-switcher a:hover { color:#E0B849; }
            .lang-section { display:none; }
            .lang-section.active { display:block; }
            .terms-block h1, .terms-block h2, .terms-block h3 { color:#212F54; margin-top:28px; margin-bottom:12px; font-weight: 700; }
            .terms-block p, .terms-block li { color:#333; line-height:1.65; font-size:15px; }
            .terms-block ul { padding-left:20px; margin-bottom:16px; }
            .sf-last-updated { color: #64748b; font-size: 14px; margin-bottom: 20px; }
        </style>

        <script>
        document.addEventListener("DOMContentLoaded", function () {
            const buttons = document.querySelectorAll(".lang-btn");
            const sections = document.querySelectorAll(".lang-section");
            let current = localStorage.getItem("sf_legal_lang") || "en";
            
            const activeSec = document.querySelector('.lang-section[data-lang="'+current+'"]');
            if (activeSec) activeSec.classList.add("active");
            else if (sections.length > 0) sections[0].classList.add("active");

            buttons.forEach(btn => {
                btn.addEventListener("click", function(e){
                    e.preventDefault();
                    const lang = btn.dataset.lang;
                    sections.forEach(s => s.classList.remove("active"));
                    const target = document.querySelector('.lang-section[data-lang="'+lang+'"]');
                    if(target) target.classList.add("active");
                    localStorage.setItem("sf_legal_lang", lang);
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function renderAdminPage(): void
    {
        $docs = get_option(self::OPTION_KEY, []);
        $activeTab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'guest_policies';
        
        $tabs = [
            'guest_policies' => 'Guest Policies',
            'terms_conditions' => 'Terms & Conditions',
            'owner_agb' => 'Owner Terms (AGB)',
            'cancellation_policy' => 'Cancellation Policy',
            'impressum' => 'Impressum',
            'privacy_policy' => 'Datenschutzerklärung'
        ];
        
        if (!array_key_exists($activeTab, $tabs)) {
            $activeTab = 'guest_policies';
        }
        
        $currentEn = !empty($docs[$activeTab]['en']) ? $docs[$activeTab]['en'] : '';
        $currentDe = !empty($docs[$activeTab]['de']) ? $docs[$activeTab]['de'] : '';
        $lastUpdated = $docs[$activeTab]['last_updated'] ?? 'Never';

        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">📄 Legal Documents Registry</h1>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Dokumente erfolgreich gespeichert und Version aktualisiert!</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['mailing'])): ?>
                <div class="notice notice-info is-dismissible"><p>📧 E-Mail-Versand an Eigentümer wurde in die Warteschlange gestellt (Hintergrundprozess).</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $name): ?>
                    <a href="?page=stayflow-core-legal&tab=<?php echo esc_attr($key); ?>" class="nav-tab <?php echo $activeTab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($name); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 20px; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <input type="hidden" name="action" value="sf_save_legal_docs">
                <input type="hidden" name="doc_tab" value="<?php echo esc_attr($activeTab); ?>">
                <?php wp_nonce_field('sf_legal_docs_nonce', 'sf_legal_csrf'); ?>

                <p style="color: #64748b; margin-top: 0;"><strong>Letzte Änderung:</strong> <?php echo esc_html($lastUpdated); ?></p>

                <h3 style="color:#082567; margin-bottom: 5px;">🇬🇧 English Version</h3>
                <?php wp_editor($currentEn, 'legal_content_en', ['textarea_rows' => 20, 'media_buttons' => false]); ?>

                <h3 style="color:#082567; margin-top: 30px; margin-bottom: 5px;">🇩🇪 Deutsche Version</h3>
                <?php wp_editor($currentDe, 'legal_content_de', ['textarea_rows' => 20, 'media_buttons' => false]); ?>

                <?php if ($activeTab === 'owner_agb'): ?>
                    <div style="margin-top: 30px; padding: 15px; background: #f8fafc; border-left: 4px solid #E0B849; border-radius: 6px;">
                        <label>
                            <input type="checkbox" name="notify_owners" value="1">
                            <strong>📢 E-Mail-Benachrichtigung an alle aktiven Eigentümer senden</strong>
                        </label>
                        <p class="description" style="margin-top: 5px;">Wenn aktiviert, wird eine formschöne Info-E-Mail über die AGB-Änderung an alle Eigentümer versendet (Admin-Accounts ausgeschlossen).</p>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary" style="background: #082567; border-color: #082567; color: #E0B849; font-weight: 600; border-radius: 8px; padding: 4px 20px;">Änderungen speichern & Version updaten</button>
                </p>
                
                <hr style="margin-top: 30px; border: 0; border-top: 1px solid #e2e8f0;">
                <p class="description"><strong>Frontend Einbindung:</strong> Verwenden Sie den Shortcode <code>[stayflow_legal_doc type="<?php echo esc_attr($activeTab); ?>"]</code> на вашей WordPress-странице, чтобы отобразить этот документ на двух языках.</p>
            </form>
        </div>
        
        <style>
            .stayflow-admin-wrap { max-width: 1100px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            .sf-page-title { color: #082567; font-weight: 800; margin-bottom: 20px; }
            .nav-tab-active { border-bottom-color: #fff !important; color: #082567 !important; font-weight: 700; }
        </style>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options') || !isset($_POST['sf_legal_csrf']) || !wp_verify_nonce((string)$_POST['sf_legal_csrf'], 'sf_legal_docs_nonce')) {
            wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
        }

        $tab = sanitize_text_field($_POST['doc_tab'] ?? 'guest_policies');
        $contentEn = wp_kses_post(wp_unslash($_POST['legal_content_en'] ?? ''));
        $contentDe = wp_kses_post(wp_unslash($_POST['legal_content_de'] ?? ''));

        $docs = get_option(self::OPTION_KEY, []);
        
        $docs[$tab] = [
            'en' => $contentEn,
            'de' => $contentDe,
            'last_updated' => current_time('Y-m-d H:i:s')
        ];

        update_option(self::OPTION_KEY, $docs);

        $mailingTriggered = false;
        if ($tab === 'owner_agb' && isset($_POST['notify_owners']) && $_POST['notify_owners'] === '1') {
            $this->initiateOwnerMailing();
            $mailingTriggered = true;
        }

        $redirectUrl = add_query_arg(['page' => 'stayflow-core-legal', 'tab' => $tab, 'updated' => '1'], admin_url('admin.php'));
        if ($mailingTriggered) {
            $redirectUrl = add_query_arg('mailing', '1', $redirectUrl);
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function initiateOwnerMailing(): void
    {
        $users = get_users(['role__not_in' => ['administrator'], 'fields' => 'ID']);
        if (empty($users)) return;

        update_option('sf_policy_mailing_queue', $users);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
    }

    /**
     * RU: Обработка очереди писем. Генерация брендированного HTML-письма.
     * EN: Processing email queue. Generating branded HTML email.
     */
    public function processEmailBatch(): void
    {
        $queue = get_option('sf_policy_mailing_queue', []);
        if (empty($queue) || !is_array($queue)) return;

        $batch = array_splice($queue, 0, self::BATCH_SIZE);
        update_option('sf_policy_mailing_queue', $queue);

        $subject = 'Wichtig: Aktualisierung unserer AGB für Vermieter (Stay4Fair)';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $link    = home_url('/owner-terms-agb/');
        // RU: Ссылка на БЕЛЫЙ логотип
        // EN: Link to WHITE logo
        $logoUrl = 'https://stay4fair.com/wp-content/uploads/2026/04/gorizontal-white.png';

        foreach ($batch as $userId) {
            $user = get_userdata((int)$userId);
            if (!$user) continue;

            $firstName = esc_html($user->first_name ?: 'Partner');

            // RU: Сборка красивого HTML-шаблона
            // EN: Building a beautiful HTML template
            $body = '
            <div style="font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 30px 10px; color: #333;">
                <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                    
                    <div style="background-color: #082567; padding: 25px; text-align: center;">
                        <img src="' . esc_url($logoUrl) . '" alt="Stay4Fair" style="max-height: 40px; width: auto; display: inline-block;">
                    </div>
                    
                    <div style="padding: 40px 30px;">
                        <h2 style="color: #082567; font-size: 22px; margin-top: 0; margin-bottom: 20px;">Aktualisierung der AGB</h2>
                        
                        <p style="font-size: 16px; line-height: 1.6; margin-bottom: 15px;">Hallo ' . $firstName . ',</p>
                        
                        <p style="font-size: 16px; line-height: 1.6; margin-bottom: 15px;">wir haben unsere <strong>Allgemeinen Geschäftsbedingungen (AGB) für Vermieter</strong> aktualisiert, um unsere Partnerschaft noch transparenter und sicherer zu gestalten.</p>
                        
                        <p style="font-size: 16px; line-height: 1.6; margin-bottom: 30px;">Bitte nehmen Sie sich einen kurzen Moment Zeit, um die Änderungen zu lesen:</p>
                        
                        <div style="text-align: center; margin: 35px 0;">
                            <a href="' . esc_url($link) . '" style="background-color: #E0B849; color: #082567; text-decoration: none; padding: 14px 28px; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block; text-transform: uppercase; letter-spacing: 0.5px;">Zu den aktualisierten AGB</a>
                        </div>

                        <p style="font-size: 13px; color: #64748b; line-height: 1.5; margin-top: 30px; border-left: 3px solid #e2e8f0; padding-left: 10px;">
                            <em>Rechtlicher Hinweis: Die weitere Nutzung unserer Plattform und Dienste nach Erhalt dieser E-Mail gilt als Zustimmung zu den neuen Bedingungen.</em>
                        </p>
                        
                        <p style="font-size: 16px; line-height: 1.6; margin-top: 30px; margin-bottom: 0;">Beste Grüße,<br><strong style="color: #082567;">Ihr Stay4Fair Team</strong></p>
                    </div>
                    
                    <div style="background-color: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;">
                        Stay4Fair (Einzelunternehmen)<br>
                        Marienwerderstr. 11, 30823 Garbsen<br><br>
                        <a href="https://stay4fair.com" style="color: #94a3b8; text-decoration: underline;">stay4fair.com</a>
                    </div>
                    
                </div>
            </div>';

            wp_mail($user->user_email, $subject, $body, $headers);
        }

        if (!empty($queue)) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK);
        }
    }
}