<?php

declare(strict_types=1);

namespace StayFlow\CPT;

// ==========================================================================
// RU: Защита от прямого доступа
// EN: Prevent direct access
// ==========================================================================
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.3.6
 * RU: Форма добавления (Новые подсказки, адаптивная кнопка отправки на мобильных, защита ABSPATH).
 * EN: Apartment form (New hints, mobile responsive submit button, ABSPATH protection).
 */
final class ApartmentProvider
{
    // ==========================================================================
    // RU: Регистрация шорткода
    // EN: Shortcode registration
    // ==========================================================================
    public function register(): void
    {
        add_shortcode('sf_add_apartment', [$this, 'renderForm']);
    }

    // ==========================================================================
    // RU: Отрисовка формы
    // EN: Form rendering
    // ==========================================================================
    public function renderForm(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte loggen Sie sich ein.</p>';
        }

        wp_enqueue_style('sf-onboarding-style', plugins_url('assets/css/onboarding.css', dirname(__FILE__, 2)));

        // RU: Безопасное получение таксономий
        // EN: Safely fetch taxonomies
        $categories = taxonomy_exists('mphb_room_type_category') ? get_terms(['taxonomy' => 'mphb_room_type_category', 'hide_empty' => false]) : [];
        $amenities  = taxonomy_exists('mphb_room_type_facility') ? get_terms(['taxonomy' => 'mphb_room_type_facility', 'hide_empty' => false]) : [];
        $apt_types  = taxonomy_exists('mphb_ra_apartment-type') ? get_terms(['taxonomy' => 'mphb_ra_apartment-type', 'hide_empty' => false]) : [];

        ob_start();
        ?>
        <style>
            /* ==========================================================================
               RU: Основные стили кнопок и элементов
               EN: Main buttons and elements styles
               ========================================================================== */
            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; gap: 8px; padding: 15px 30px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 16px; text-decoration: none; text-align: center; }
            .sf-3d-btn::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; }
            .sf-3d-btn:hover { background-color: #082567 !important; color: #E0B849 !important; transform: translateY(-2px) !important; }

            .sf-cards-container { display: flex; gap: 15px; margin-top: 10px; }
            .sf-policy-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; flex: 1; cursor: pointer; transition: all 0.3s; background: #fff; position: relative; }
            .sf-policy-card:hover { border-color: #cbd5e1; }
            .sf-policy-card.active { border-color: #E0B849; background: #fdf8ed; box-shadow: 0 4px 15px rgba(224, 184, 73, 0.15); }
            .sf-policy-card h4 { margin: 0 0 10px 0; color: #082567; font-size: 16px; }
            .sf-policy-card p { font-size: 13px; color: #64748b; margin: 0; line-height: 1.5; }
            .sf-policy-input { width: 55px; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; margin: 0 4px; font-weight: bold; }
            
            .sf-field-hint { font-size: 12px; color: #64748b; margin: 4px 0 0 0; line-height: 1.4; }
            
            .sf-drop-zone { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 40px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.3s ease; }
            .sf-drop-zone.dragover { border-color: #E0B849; background: #fdf8ed; }
            .sf-drop-zone-icon { font-size: 40px; margin-bottom: 15px; display: block; }
            .sf-file-list { margin-top: 15px; font-size: 13px; color: #082567; text-align: left; max-height: 150px; overflow-y: auto; }
            .sf-file-item { background: #e2e8f0; padding: 5px 10px; border-radius: 4px; margin-bottom: 5px; display: inline-block; margin-right: 5px; }

            .sf-checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 10px; }
            .sf-checkbox-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #1e293b; }
            .sf-checkbox-item input { margin: 0; }

            .sf-submit-wrapper { margin-top: 40px; text-align: right; border-top: 1px solid #e2e8f0; padding-top: 20px; }

            /* ==========================================================================
               RU: Адаптивность для мобильных устройств
               EN: Mobile responsive adjustments
               ========================================================================== */
            @media(max-width: 768px) { 
                .sf-cards-container { flex-direction: column; } 
                
                /* RU: Делаем кнопку сохранения на всю ширину и разрешаем перенос текста */
                /* EN: Make submit button full width and allow text wrapping */
                .sf-3d-btn {
                    width: 100% !important;
                    padding: 12px 15px !important;
                    font-size: 14px !important;
                    justify-content: center !important;
                    white-space: normal !important;
                    line-height: 1.3 !important;
                }
                .sf-submit-wrapper {
                    text-align: center !important;
                }
            }
        </style>

        <div class="sf-onboarding-container" style="max-width: 1140px;">
            <form id="sf-apartment-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sf_process_add_apartment">
                <?php wp_nonce_field('sf_add_apt_action', 'sf_add_apt_nonce'); ?>

                <h2 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px;">A. Basisdaten</h2>
                
                <div class="sf-form-group">
                    <label>Name der Unterkunft *</label>
                    <input type="text" name="apt_name" placeholder="z.B. Gemütliches Studio im Zentrum" required>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label style="margin-bottom: 5px; display: block;">Beschreibung (Optional, aber empfohlen)</label>
                    <p class="sf-field-hint" style="margin-top: 0; margin-bottom: 10px;">Beschreiben Sie die Highlights Ihrer Unterkunft. Bitte verwenden Sie keine Links.</p>
                    <?php 
                        wp_editor('', 'apt_description', [
                            'media_buttons' => false,
                            'textarea_rows' => 8,
                            'teeny'         => true,
                            'quicktags'     => false,
                            'tinymce'       => [
                                'toolbar1' => 'bold,italic,bullist,numlist,undo,redo'
                            ]
                        ]); 
                    ?>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Adresse (Straße, Hausnummer, PLZ, Stadt) *</label>
                    <input type="text" name="apt_address" required>
                </div>

                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Name auf dem Klingelschild *</label>
                        <input type="text" name="apt_doorbell" required>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Kontaktnummer der Unterkunft *</label>
                        <input type="text" name="apt_contact_phone" placeholder="+49..." required>
                    </div>
                </div>

                <div class="sf-form-group">
                    <label>Wohnung ID / Registrierungsnummer</label>
                    <input type="text" name="apt_reg_id">
                </div>

                <h2 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px; margin-top: 30px;">B. Preise & Kalender</h2>

                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Verkaufspreis pro Nacht (€) *</label>
                        <input type="number" name="apt_price" step="0.01" placeholder="z.B. 120" required>
                        <p class="sf-field-hint">Ihr Preis inkl. 15% Provision.</p>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Mindestaufenthalt (Nächte) *</label>
                        <input type="number" name="apt_min_stay" value="1" min="1" required>
                    </div>
                </div>

                <div class="sf-form-group" style="margin-top: 15px;">
                    <label>iCal Kalender-Import (Optional)</label>
                    <input type="url" name="apt_ical" placeholder="https://...">
                    <p class="sf-field-hint">Fügen Sie hier Ihren iCal-Link (z.B. von Airbnb oder Booking) ein, um den Kalender zu synchronisieren.</p>
                </div>

                <div class="sf-form-group" style="margin-top: 25px;">
                    <label>Stornierungsbedingungen *</label>
                    <input type="hidden" name="apt_cancellation" id="apt_cancellation_val" value="flexible">
                    
                    <div class="sf-cards-container">
                        <div class="sf-policy-card active" id="card_flexible" onclick="sfSetPolicy('flexible')">
                            <h4>Flexibel</h4>
                            <p>Kostenlose Stornierung bis <input type="number" name="apt_flex_days" id="apt_flex_days" value="14" min="1" class="sf-policy-input" onclick="event.stopPropagation()"> Tage vor Anreise.</p>
                        </div>
                        <div class="sf-policy-card" id="card_non_refundable" onclick="sfSetPolicy('non_refundable')">
                            <h4>Nicht erstattbar</h4>
                            <p>Der Gast zahlt bei Stornierung den vollen Preis.</p>
                        </div>
                    </div>
                </div>

                <div class="sf-consent-block" style="background: #fdf8ed; padding: 15px; border-radius: 8px; border: 1px solid #E0B849; margin-bottom: 20px; margin-top: 25px;">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="checkbox" name="apt_loyalty" id="apt_loyalty" value="1" style="margin-top: 4px;">
                        <div>
                            <label for="apt_loyalty" style="font-weight: 600; font-size: 14px; color: #082567;">Am Stay4Fair Treueprogramm teilnehmen</label>
                            <p class="sf-field-hint" style="margin-top: 4px;">Am "Fair Return" Programm teilnehmen (-10%)
Stammgäste, die Ihre Unterkunft erneut buchen, erhalten 10% Rabatt. Als Dankeschön senken wir unsere Vermittlungsprovision für diese Buchungen von 15% auf 10%. Eine Win-Win-Situation für Sie und Ihre Gäste!</p>
                        </div>
                    </div>
                </div>

                <h2 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px; margin-top: 30px;">C. Ausstattung & Kapazität</h2>

                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Max. Erwachsene *</label>
                        <input type="number" name="apt_adults" value="2" min="1" required>
                        <p class="sf-field-hint"><strong>Tipp:</strong> Sie können 1 Bett/Sofa als 1 Person zählen oder – als attraktiven Bonus für potenzielle Gäste – ein Doppelbett für 2 Personen berechnen. Wir empfehlen, den Preis dafür nicht stark zu erhöhen.</p>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Max. Kinder</label>
                        <input type="number" name="apt_children" value="0" min="0">
                        <p class="sf-field-hint">Lassen Sie dieses Feld auf 0, wenn Ihre Unterkunft nicht speziell für Kinder gewünscht/geeignet ist.</p>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 15px;">
                    <?php if (!empty($categories) && !is_wp_error($categories)): ?>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Kategorie *</label>
                        <select name="apt_category" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sf-field-hint">Dient zur Gruppierung und Darstellung auf der Webseite.</p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($apt_types) && !is_wp_error($apt_types)): ?>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Apartment Typ (Attribut) *</label>
                        <select name="apt_attribute_type" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($apt_types as $type): ?>
                                <option value="<?php echo esc_attr($type->term_id); ?>"><?php echo esc_html($type->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sf-field-hint">Wichtig für die Suchfunktion, damit Gäste gezielt filtern können.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($amenities) && !is_wp_error($amenities)): ?>
                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Ausstattung (Amenities)</label>
                    <div class="sf-checkbox-grid">
                        <?php foreach ($amenities as $amenity): ?>
                            <label class="sf-checkbox-item">
                                <input type="checkbox" name="apt_amenities[]" value="<?php echo esc_attr($amenity->term_id); ?>">
                                <?php echo esc_html($amenity->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <h2 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px; margin-top: 30px;">D. Auszahlung & DAC7</h2>
                
                <div class="sf-form-group">
                    <label>Kontoinhaber *</label>
                    <input type="text" name="apt_bank_name" required>
                </div>
                <div class="sf-form-group">
                    <label>IBAN *</label>
                    <input type="text" name="apt_bank_iban" required>
                </div>
                <div class="sf-form-group">
                    <label>Steuernummer (Für DAC7)</label>
                    <input type="text" name="apt_tax_id">
                    <p class="sf-field-hint">Gemäß der EU-Richtlinie (DAC7) benötigen wir diese Angabe. Weitere Details in unseren <a href="https://stay4fair.com/owner-terms-agb/" target="_blank" style="color:#082567; text-decoration:underline;">AGB</a>.</p>
                </div>

                <h2 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px; margin-top: 30px;">E. Fotos</h2>
                
                <div class="sf-form-group">
                    <label style="margin-bottom: 10px; display: block;">Fotos der Unterkunft (Max. 15 Bilder) *</label>
                    <div id="sf-drop-zone" class="sf-drop-zone" onclick="document.getElementById('apt_photos').click()">
                        <span class="sf-drop-zone-icon">📸</span>
                        <p>Fotos hierher ziehen oder klicken zum Auswählen</p>
                        <span style="font-size: 13px; color: #64748b;">(JPEG, PNG, WebP) - Das erste Bild wird Ihr Titelbild</span>
                        <input type="file" name="apt_photos[]" id="apt_photos" multiple accept="image/jpeg, image/png, image/webp" required style="display: none;">
                    </div>
                    <div id="file-preview-list" class="sf-file-list"></div>
                </div>

                <div class="sf-submit-wrapper">
                    <button type="submit" class="sf-3d-btn">Apartment zur Prüfung einreichen</button>
                </div>
            </form>
        </div>

        <script>
        // ==========================================================================
        // RU: Обработка UI логики формы
        // EN: UI logic handling
        // ==========================================================================
        function sfSetPolicy(type) {
            document.getElementById('apt_cancellation_val').value = type;
            document.getElementById('card_flexible').classList.remove('active');
            document.getElementById('card_non_refundable').classList.remove('active');
            document.getElementById('card_' + type).classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('sf-drop-zone');
            const fileInput = document.getElementById('apt_photos');
            const previewList = document.getElementById('file-preview-list');

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });
            function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
            });

            dropZone.addEventListener('drop', function(e) {
                let dt = e.dataTransfer;
                let files = dt.files;
                if(files.length > 15) { alert('Bitte laden Sie maximal 15 Fotos hoch.'); return; }
                fileInput.files = files; 
                updatePreview();
            });

            fileInput.addEventListener('change', function() {
                if(this.files.length > 15) { alert('Bitte laden Sie maximal 15 Fotos hoch.'); }
                updatePreview();
            });

            function updatePreview() {
                previewList.innerHTML = '';
                if(fileInput.files.length > 0) {
                    const filesToShow = Array.from(fileInput.files).slice(0, 15);
                    filesToShow.forEach(file => {
                        let span = document.createElement('span');
                        span.className = 'sf-file-item';
                        span.textContent = file.name;
                        previewList.appendChild(span);
                    });
                }
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}