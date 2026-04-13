<?php

declare(strict_types=1);

namespace StayFlow\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.2.0
 * RU: Провайдер глобального уведомления. Добавлена поддержка кастомных ссылок и текста для кнопки.
 * EN: Global site notice provider. Added support for custom button links and text.
 */
final class SiteNoticeProvider
{
    public function register(): void
    {
        add_action('wp_footer', [$this, 'renderPopup']);
    }

    public function renderPopup(): void
    {
        if (is_admin()) return;

        $options = get_option('stayflow_site_notice_settings', []);
        
        if (empty($options['enabled'])) {
            return;
        }

        $logo_url    = !empty($options['logo_url']) ? $options['logo_url'] : '';
        $cookie_days = !empty($options['cookie_days']) ? (int)$options['cookie_days'] : 1;
        $content     = !empty($options['content']) ? $options['content'] : '';
        
        // RU: Новые настройки кнопки
        $btn_text    = !empty($options['btn_text']) ? $options['btn_text'] : 'Verstanden / Got it';
        $btn_url     = !empty($options['btn_url']) ? $options['btn_url'] : '';
        $btn_target  = !empty($options['btn_target']) ? $options['btn_target'] : '_self';

        if (empty($content)) return;

        ob_start();
        ?>
        <style>
            /* Стили остаются прежними для сохранения дизайна */
            .sf-global-notice-overlay {
                position: fixed;
                top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(8, 37, 103, 0.25);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                z-index: 999999;
                display: flex;
                justify-content: center;
                align-items: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.5s ease, visibility 0.5s ease;
            }
            .sf-global-notice-overlay.active { opacity: 1; visibility: visible; }

            .sf-global-notice-modal {
                background: rgba(255, 255, 255, 0.65);
                backdrop-filter: blur(30px) saturate(150%);
                -webkit-backdrop-filter: blur(30px) saturate(150%);
                border: 1px solid rgba(255, 255, 255, 0.8);
                box-shadow: 0 25px 50px rgba(8, 37, 103, 0.15), inset 0 0 0 1px rgba(255, 255, 255, 0.5), inset 0 15px 30px rgba(255, 255, 255, 0.6);
                border-radius: 20px;
                padding: 30px 40px 25px 40px; 
                width: 90%;
                max-width: 600px; 
                position: relative;
                transform: translateY(30px) scale(0.95);
                transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                text-align: center;
            }
            .sf-global-notice-overlay.active .sf-global-notice-modal { transform: translateY(0) scale(1); }

            .sf-global-notice-close {
                position: absolute; top: 15px; right: 15px; width: 30px; height: 30px; border-radius: 50%;
                background: rgba(255,255,255,0.5); border: 1px solid rgba(255,255,255,0.8);
                color: #64748b; font-size: 22px; font-family: Arial, sans-serif;
                display: flex; align-items: center; justify-content: center; cursor: pointer;
                transition: all 0.2s ease; line-height: 0; padding: 0; padding-bottom: 3px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            }
            .sf-global-notice-close:hover { background: #ffffff; color: #082567; transform: scale(1.1); }

            .sf-global-notice-logo { margin-bottom: 20px; }
            .sf-global-notice-logo img { max-height: 45px; width: auto; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.05)); }

            .sf-global-notice-content { font-family: 'Segoe UI', Roboto, sans-serif; color: #1e293b; font-size: 15px; line-height: 1.5; }
            .sf-global-notice-content h2 { color: #082567; font-size: 22px; font-weight: 800; margin: 0 0 10px 0; }
            .sf-global-notice-content hr { border: 0; height: 1px; background: linear-gradient(to right, transparent, rgba(8, 37, 103, 0.2), transparent); margin: 15px 0; }

            .sf-global-btn-wrap { margin-top: 25px; }
            .sf-notice-btn {
                position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                transition: all 0.25s ease !important; cursor: pointer !important; z-index: 2; display: inline-flex;
                align-items: center; justify-content: center; padding: 12px 30px; font-family: 'Segoe UI', Roboto, sans-serif;
                font-weight: 700; font-size: 15px; text-decoration: none !important;
                background-color: #082567 !important; color: #E0B849 !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important;
                background-blend-mode: overlay; -webkit-appearance: none !important;
            }
            .sf-notice-btn::before {
                content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; pointer-events: none !important;
            }
            .sf-notice-btn:hover { background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.1) 100%) !important; transform: translateY(-2px) !important; }
            .sf-notice-btn span { position: relative; z-index: 3; }
        </style>

        <div id="sf-global-notice" class="sf-global-notice-overlay" data-days="<?php echo esc_attr((string)$cookie_days); ?>">
            <div class="sf-global-notice-modal">
                <button class="sf-global-notice-close" aria-label="Close">&times;</button>
                
                <?php if ($logo_url): ?>
                <div class="sf-global-notice-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Notice">
                </div>
                <?php endif; ?>

                <div class="sf-global-notice-content">
                    <?php echo wp_kses_post($content); ?>
                </div>

                <div class="sf-global-btn-wrap">
                    <?php if ($btn_url): ?>
                        <a href="<?php echo esc_url($btn_url); ?>" target="<?php echo esc_attr($btn_target); ?>" class="sf-notice-btn" id="sf-global-notice-btn-link">
                            <span><?php echo esc_html($btn_text); ?></span>
                        </a>
                    <?php else: ?>
                        <button class="sf-notice-btn" id="sf-global-notice-btn-close">
                            <span><?php echo esc_html($btn_text); ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var overlay = document.getElementById('sf-global-notice');
                if (!overlay) return;
                
                var cookieDays = parseInt(overlay.getAttribute('data-days')) || 1;
                var cacheKey = 'sf_notice_dismissed_v4'; // Update version to force show for everyone
                var dismissUntil = localStorage.getItem(cacheKey);
                var now = new Date().getTime();

                if (!dismissUntil || now > parseInt(dismissUntil)) {
                    setTimeout(function() { overlay.classList.add('active'); }, 500); 
                }

                function closeNotice() {
                    overlay.classList.remove('active');
                    var expireTime = now + (cookieDays * 24 * 60 * 60 * 1000);
                    localStorage.setItem(cacheKey, expireTime);
                }

                overlay.querySelector('.sf-global-notice-close').addEventListener('click', closeNotice);
                
                // RU: Если кнопка — просто закрытие
                var closeBtn = document.getElementById('sf-global-notice-btn-close');
                if (closeBtn) {
                    closeBtn.addEventListener('click', closeNotice);
                }

                // RU: Если кнопка — ссылка, всё равно закрываем попап (записываем куку), чтобы не вылез на следующей странице
                var linkBtn = document.getElementById('sf-global-notice-btn-link');
                if (linkBtn) {
                    linkBtn.addEventListener('click', closeNotice);
                }
                
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) closeNotice();
                });
            });
        </script>
        <?php
        echo ob_get_clean();
    }
}