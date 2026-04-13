<?php

declare(strict_types=1);

namespace StayFlow\Integration;

use StayFlow\Settings\SettingsStore;

// ==========================================================================
// RU: Защита от прямого доступа
// EN: Prevent direct access
// ==========================================================================
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.6.1
 * RU: Шорткод [sf_owner_steps] для вывода 8 шагов-выжимок из AGB.
 * EN: Shortcode [sf_owner_steps] to display 8 workflow steps based on AGB.
 * * [1.6.1]: Исправлена мобильная верстка (flex-direction: column внутри карточки).
 * * [1.6.0]: Исправлено получение комиссии — теперь данные берутся динамически из SettingsStore.
 */
final class OwnerStepsShortcode
{
    /**
     * RU: Регистрация шорткода.
     * EN: Register shortcode.
     */
    public function register(): void
    {
        add_shortcode('sf_owner_steps', [$this, 'render']);
    }

    /**
     * RU: Подготовка массива шагов.
     * EN: Prepare steps array.
     */
    private function get_steps(): array
    {
        // RU: Получаем динамическую комиссию из настроек плагина (через SettingsStore)
        // EN: Get dynamic commission from plugin settings (via SettingsStore)
        $settings = get_option(SettingsStore::OPTION_KEY, SettingsStore::defaults());
        $commission = $settings['commission_default'] ?? 15.0;

        // RU: Обработка случая, если значение введено как 0.15 вместо 15
        // EN: Handle cases where the value is entered as 0.15 instead of 15
        if ($commission > 0.0 && $commission <= 1.0) {
            $commission *= 100;
        }

        return [
            [
                'number' => '01',
                'title'  => 'Registrierung & Portal',
                'desc'   => 'Fülle das Formular aus und bestätige deine E-Mail, um sofort Zugang zum sicheren Owner-Portal zu erhalten. Dort kannst du direkt als Gastgeber loslegen.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>'
            ],
            [
                'number' => '02',
                'title'  => 'Wohnungs-Setup',
                'desc'   => 'Lade im Portal bequem Fotos hoch, verwalte deinen Kalender für alle Objekte und hinterlege deine Auszahlungsdaten. Alles an einem zentralen Ort.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>'
            ],
            [
                'number' => '03',
                'title'  => 'Hotelstandard',
                'desc'   => 'Die Wohnung muss neutral sein (keine persönlichen Wertsachen), sauber und mit frischer, heller Hotel-Bettwäsche ausgestattet sein. Das garantiert beste Bewertungen.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 4v16M22 4v16M4 8h16M4 16h16M12 4v16"></path></svg>'
            ],
            [
                'number' => '04',
                'title'  => 'Faires Preismodell',
                'desc'   => sprintf(
                    'Du legst deinen Endpreis fest (inkl. deiner Steuern & City Tax). Wir behalten lediglich %s%% Provision ein und übernehmen die lästige DAC7-Meldung für dich.',
                    esc_html((string)$commission)
                ),
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>'
            ],
            [
                'number' => '05',
                'title'  => '24h Bestätigung',
                'desc'   => 'Wir senden dir geprüfte Messegäste. Du hast genau 24 Stunden Zeit, die Anfrage zu bestätigen, bevor sie automatisch verfällt. Schnelle Antworten lohnen sich!',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>'
            ],
            [
                'number' => '06',
                'title'  => 'Schlüssel & Kontakt',
                'desc'   => 'Du bist während des Aufenthalts telefonisch erreichbar und organisierst die persönliche Schlüsselübergabe mit den Gästen. Ein freundlicher Empfang ist das A und O.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>'
            ],
            [
                'number' => '07',
                'title'  => 'Sicherheit & Storno',
                'desc'   => 'Schutz vor Ausfällen: Storniert ein Gast, erhältst du deine Auszahlung (abzgl. Provision) gemäß deiner Stornierungsrichtlinie. Achtung: Eigene Doppelbuchungen (Overbooking) sind streng untersagt – bei Verschulden trägst du die Umzugskosten für den Gast.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>'
            ],
            [
                'number' => '08',
                'title'  => 'Pünktliche Auszahlung',
                'desc'   => 'Lehn dich zurück. Wir sichern die Zahlung der Gäste und überweisen dein Geld in der Regel 3–7 Werktage nach Abreise. Sicher und transparent direkt auf dein Konto.',
                'icon'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>'
            ]
        ];
    }

    /**
     * RU: Рендеринг шорткода.
     * EN: Render shortcode.
     */
    public function render(): string
    {
        $steps = $this->get_steps();
        ob_start();
        ?>
        
        <div class="sf-owner-steps-wrapper">
            <div class="sf-steps-header">
                <h2>So funktioniert Stay4Fair</h2>
                <p>Transparent, sicher und fair. Die wichtigsten Spielregeln für erfolgreiche Gastgeber.</p>
            </div>

            <div class="sf-steps-grid">
                <?php foreach ($steps as $step): ?>
                    <div class="sf-step-card">
                        <div class="sf-step-number"><?php echo esc_html($step['number']); ?></div>
                        <div class="sf-step-icon">
                            <?php echo $step['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <h3 class="sf-step-title"><?php echo esc_html($step['title']); ?></h3>
                        <p class="sf-step-desc"><?php echo esc_html($step['desc']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
            /* ==========================================
               SECTION: CSS (StayFlow Heavy 3D Style)
               ========================================== */
            .sf-owner-steps-wrapper {
                font-family: 'Manrope', sans-serif;
                max-width: 1400px;
                margin: 0 auto 60px auto;
                padding: 20px;
            }

            .sf-steps-header {
                text-align: center;
                margin-bottom: 50px;
            }

            .sf-steps-header h2 {
                color: #082567;
                font-size: clamp(28px, 4vw, 36px);
                font-weight: 800;
                margin-bottom: 15px;
                letter-spacing: -0.02em;
            }

            .sf-steps-header p {
                color: #64748b;
                font-size: clamp(16px, 2vw, 18px);
                font-weight: 500;
            }

            .sf-steps-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 25px;
                position: relative;
            }

            .sf-step-card {
                background: #ffffff;
                border: 1px solid rgba(8, 37, 103, 0.08);
                border-radius: 20px;
                padding: 30px 25px;
                position: relative;
                transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                box-shadow: 0 10px 30px rgba(0,0,0,0.03);
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                z-index: 2;
                overflow: hidden;
            }

            .sf-step-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 20px 40px rgba(8, 37, 103, 0.12);
                border-color: #E0B849;
            }

            .sf-step-number {
                position: absolute;
                top: 10px;
                right: 15px;
                font-size: 64px;
                font-weight: 800;
                color: rgba(8, 37, 103, 0.03);
                line-height: 1;
                pointer-events: none;
                transition: color 0.3s ease, transform 0.3s ease;
            }

            .sf-step-card:hover .sf-step-number {
                color: rgba(224, 184, 73, 0.15);
                transform: scale(1.1) translate(-5px, 5px);
            }

            .sf-step-icon {
                color: #E0B849;
                background: rgba(224, 184, 73, 0.15);
                padding: 14px;
                border-radius: 14px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 52px;
                height: 52px;
                box-sizing: border-box;
            }

            .sf-step-title {
                color: #082567;
                font-size: 17px;
                font-weight: 800;
                margin: 0 0 10px 0;
                line-height: 1.3;
            }

            .sf-step-desc {
                color: #475569;
                font-size: 13px;
                line-height: 1.6;
                margin: 0;
            }

            @media (max-width: 1024px) {
                .sf-steps-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            /* ==========================================
               RU: Адаптивность для мобильных устройств
               EN: Mobile responsive adjustments
               ========================================== */
            @media (max-width: 600px) {
                .sf-steps-grid {
                    grid-template-columns: 1fr;
                    gap: 15px;
                }
                .sf-step-card { 
                    padding: 25px 20px; 
                    flex-direction: column; /* Изменено с row на column */
                    align-items: flex-start; /* Изменено для выравнивания по левому краю */
                    gap: 0;
                }
                .sf-step-icon {
                    margin-bottom: 15px; /* Вернули отступ, который был 0 */
                    min-width: auto;
                }
                .sf-step-number {
                    top: 15px;
                    transform: none; /* Убрано смещение translateY(-50%) */
                    right: 15px;
                    font-size: 52px;
                }
            }
        </style>

        <?php
        return ob_get_clean();
    }
}