<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.2.2
 * RU: Полная форма редактирования:
 * - [CRITICAL FIX]: Проверка прав теперь учитывает кастомное поле bsbt_owner_id (устранение ошибки Zugriff verweigert).
 * - [FIX]: Исправлена валидация iCal ссылок (JS Alert + preventDefault).
 * - [NEW]: Динамический текст-подсказка для цены в зависимости от бизнес-модели.
 * EN: Full edit form with permissions fix and dynamic price hint based on business model.
 */
final class ApartmentEditProvider
{
    // === SECTION: Registration ===

    public function register(): void
    {
        add_shortcode('sf_edit_apartment', [$this, 'renderForm']);
    }

    // === SECTION: Render Form ===

    public function renderForm(): string
    {
        // === SECTION: Auth Check ===
        if (!is_user_logged_in()) {
            return '<p>Bitte loggen Sie sich ein.</p>';
        }

        $userId = get_current_user_id();
        $apt_id = isset($_GET['apt_id']) ? (int)$_GET['apt_id'] : 0;
        $post = get_post($apt_id);
        
        // RU: Проверяем и системного автора, и назначеного через метабокс
        $bsbt_owner = (int) get_post_meta($apt_id, 'bsbt_owner_id', true);
        
        if (!$post || $post->post_type !== 'mphb_room_type' || ((int)$post->post_author !== $userId && $bsbt_owner !== $userId)) {
            return '<div style="padding: 20px; background: #fef2f2; color: #991b1b; border: 1px solid #f87171; border-radius: 8px;">Zugriff verweigert. Diese Unterkunft existiert nicht oder gehört Ihnen nicht.</div>';
        }

        // === SECTION: Data Extraction ===
        $title       = $post->post_title;
        $description = $post->post_content;
        $address     = get_post_meta($apt_id, 'address', true);
        $doorbell    = get_post_meta($apt_id, 'doorbell_name', true);
        $phone       = get_post_meta($apt_id, 'owner_phone', true);
        
        $owner_name  = get_post_meta($apt_id, 'owner_name', true);
        $owner_email = get_post_meta($apt_id, 'owner_email', true);
        $house_rules = get_post_meta($apt_id, 'house_rules', true);
        
        $reg_id      = get_post_meta($apt_id, '_sf_commune_reg_id', true);
        $status      = $post->post_status; 
        $price       = get_post_meta($apt_id, '_sf_selling_price', true);
        $min_stay    = get_post_meta($apt_id, 'sf_min_stay', true) ?: 1;

        // RU: Извлекаем бизнес-модель и настройки комиссии
        // EN: Extract business model and commission settings
        $business_model = get_post_meta($apt_id, '_bsbt_business_model', true) ?: 'model_a';
        $stayflow_settings = get_option('stayflow_core_settings', []);
        $commission_rate = !empty($stayflow_settings['commission_default']) ? $stayflow_settings['commission_default'] : '15';

        // === SECTION: iCal Links (Multi-fetch) ===
        global $wpdb;
        $export_url = '';
        
        $room_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value = %d",
            $apt_id
        ));

        if (!empty($room_ids)) {
            $room_id = (int)$room_ids[0];
            $export_url = home_url('/?feed=mphb.ics&accommodation_id=' . $room_id);
        }

        $ical_import_raw = get_post_meta($apt_id, '_sf_ical_import', true);
        $ical_import_urls = [];
        if (!empty($ical_import_raw)) {
            $decoded = json_decode($ical_import_raw, true);
            if (is_array($decoded)) {
                $ical_import_urls = $decoded;
            } else {
                $ical_import_urls = [$ical_import_raw];
            }
        }
        if (empty($ical_import_urls)) {
            $ical_import_urls = [''];
        }

        // === SECTION: Policies & Capacities ===
        $cancel_pol  = get_post_meta($apt_id, 'sf_cancellation_policy', true) ?: 'free_cancellation';
        $cancel_days = get_post_meta($apt_id, 'sf_cancellation_days', true) ?: 14;
        $loyalty     = (int)get_post_meta($apt_id, '_sf_fair_return', true);
        $adults      = get_post_meta($apt_id, 'mphb_adults_capacity', true) ?: 2;
        $children    = get_post_meta($apt_id, 'mphb_children_capacity', true) ?: 0;
        
        $bank_name   = get_post_meta($apt_id, 'kontoinhaber', true) ?: get_user_meta($userId, 'bsbt_account_holder', true);
        $iban        = get_post_meta($apt_id, 'kontonummer', true) ?: get_user_meta($userId, 'bsbt_iban', true);
        $tax_id      = get_post_meta($apt_id, 'steuernummer', true) ?: get_user_meta($userId, 'bsbt_tax_number', true);

        // === SECTION: Taxonomies & Galleries ===
        $curr_cats   = wp_get_object_terms($apt_id, 'mphb_room_type_category', ['fields' => 'ids']);
        $curr_attrs  = wp_get_object_terms($apt_id, 'mphb_ra_apartment-type', ['fields' => 'ids']);
        $curr_amen   = wp_get_object_terms($apt_id, 'mphb_room_type_facility', ['fields' => 'ids']);
        
        $all_cats    = get_terms(['taxonomy' => 'mphb_room_type_category', 'hide_empty' => false]);
        $all_attrs   = get_terms(['taxonomy' => 'mphb_ra_apartment-type', 'hide_empty' => false]);
        $all_amen    = get_terms(['taxonomy' => 'mphb_room_type_facility', 'hide_empty' => false]);

        $thumb_id    = get_post_thumbnail_id($apt_id);
        $gal_string  = get_post_meta($apt_id, 'mphb_gallery', true);
        $gal_arr     = $gal_string ? explode(',', $gal_string) : [];
        $current_images = [];
        if ($thumb_id) $current_images[] = $thumb_id;
        foreach ($gal_arr as $gid) { if ($gid && !in_array($gid, $current_images)) $current_images[] = $gid; }

        $amenity_translations = [
            'WiFi' => 'WLAN', 'Internet' => 'Internet', 'TV' => 'Fernseher', 'Kitchen' => 'Küche',
            'Air conditioning' => 'Klimaanlage', 'Heating' => 'Heizung', 'Washing machine' => 'Waschmaschine',
            'Dryer' => 'Wäschetrockner', 'Parking' => 'Parkplatz', 'Free parking' => 'Kostenloser Parkplatz',
            'Balcony' => 'Balkon', 'Pool' => 'Pool', 'Iron' => 'Bügeleisen', 'Hair dryer' => 'Haartrockner',
            'Coffee maker' => 'Kaffeemaschine', 'Dishwasher' => 'Spülmaschine', 'Microwave' => 'Mikrowelle',
            'Refrigerator' => 'Kühlschrank', 'Elevator' => 'Aufzug', 'Pets allowed' => 'Haustiere erlaubt',
            'Smoking allowed' => 'Rauchen erlaubt', 'Workspace' => 'Arbeitsplatz', 'Essentials' => 'Grundausstattung',
            'Fire extinguisher' => 'Feuerlöscher', 'First aid kit' => 'Erste-Hilfe-Set', 'Smoke detector' => 'Rauchmelder',
            'Private entrance' => 'Eigener Eingang', 'Patio or balcony' => 'Terrasse oder Balkon', 'Backyard' => 'Garten',
            'BBQ grill' => 'Grill', 'Hot tub' => 'Whirlpool', 'Breakfast' => 'Frühstück', 'Crib' => 'Kinderbett',
            'High chair' => 'Hochstuhl', 'Bathtub' => 'Badewanne', 'Oven' => 'Backofen', 'Stove' => 'Herd', 'Bed linens' => 'Bettwäsche'
        ];

        wp_enqueue_style('sf-onboarding-style', plugins_url('assets/css/onboarding.css', dirname(__FILE__, 2)));
        ob_start();
        ?>
        <style>
            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 14px; text-decoration: none; text-align: center; }
            .sf-3d-btn::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; }
            .sf-3d-btn:hover { background-color: #082567 !important; color: #E0B849 !important; transform: translateY(-2px) !important; }
            .sf-3d-btn-navy { background-color: #082567 !important; color: #E0B849 !important; }
            .sf-3d-btn-navy:hover { background-color: #E0B849 !important; color: #082567 !important; }
            
            .sf-field-hint { font-size: 12px; color: #64748b; margin: 4px 0 0 0; line-height: 1.4; }
            .sf-edit-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #E0B849; padding-bottom: 15px; margin-bottom: 30px; }
            .sf-edit-header h2 { color: #082567; margin: 0; font-size: 24px; font-weight: 700; }

            .sf-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; margin-top: 15px; }
            .sf-gallery-item { position: relative; border-radius: 8px; overflow: hidden; background: #e2e8f0; height: 130px; cursor: grab; border: 2px solid transparent; transition: transform 0.2s; }
            .sf-gallery-item img { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
            .sf-gallery-badge { position: absolute; top: 8px; left: 8px; background: #E0B849; color: #082567; font-size: 10px; font-weight: bold; padding: 3px 8px; border-radius: 4px; z-index: 10; display: none; }
            .sf-gallery-item:first-child .sf-gallery-badge { display: block; }
            
            .sf-gallery-delete { position: absolute; bottom: 8px; right: 8px; background: #dc2626 !important; color: #ffffff !important; border: none !important; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 10px; font-weight: bold; text-transform: uppercase; box-shadow: 0 2px 4px rgba(0,0,0,0.3); z-index: 99 !important; }
            .sf-gallery-delete:hover { background: #991b1b !important; }

            .sf-status-container { display: flex; gap: 15px; margin-bottom: 30px; }
            .sf-status-tile { flex: 1; border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px; cursor: pointer; transition: 0.3s; background: #fff; }
            .sf-status-tile:hover { border-color: #cbd5e1; }
            .sf-status-tile.active-online { border-color: #22c55e; background: #dcfce7; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.1); }
            .sf-status-tile.active-offline { border-color: #ef4444; background: #fee2e2; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1); }
            .sf-status-tile h4 { margin: 0 0 8px 0; color: #082567; font-size: 16px; font-weight: 700; }
            .sf-status-tile p { font-size: 12px; color: #64748b; margin: 0; line-height: 1.4; }
            
            .sf-ical-box { background: #fdf8ed; border: 2px solid #E0B849; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
            .sf-ical-input-group { display: flex; gap: 10px; align-items: center; margin-top: 10px; }
            .sf-ical-input-group input { flex: 1; background: #fff; border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 13px; outline: none; }
            
            .sf-cards-container { display: flex; gap: 15px; margin-top: 10px; width: 100%; }
            .sf-policy-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; flex: 1; cursor: pointer; transition: 0.3s; background: #fff; display: flex; flex-direction: column; }
            .sf-policy-card.active { border-color: #E0B849; background: #fdf8ed; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
            .sf-policy-input { width: 60px; text-align: center; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px; font-weight: bold; }

            .sf-drop-zone { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 40px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; margin-top: 20px; }
            .sf-drop-zone:hover { border-color: #E0B849; background: #fdf8ed; }
            .sf-drop-zone-icon { font-size: 40px; display: block; margin-bottom: 10px; }

            .sf-checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 10px; }
            .sf-checkbox-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #1e293b; cursor: pointer; }
        </style>

        <div class="sf-onboarding-container" style="max-width: 1140px;">
            <div class="sf-edit-header">
                <h2>Apartment bearbeiten: <span style="color: #E0B849;"><?php echo esc_html($title); ?></span></h2>
                <a href="<?php echo home_url('/owner-apartments/'); ?>" class="sf-3d-btn sf-3d-btn-navy">← Zurück zur Liste</a>
            </div>

            <?php if ($export_url): ?>
            <div class="sf-ical-box">
                <h4 style="margin:0 0 5px 0; color:#082567;">📅 Ihr Stay4Fair Kalender-Link (iCal Export)</h4>
                <p class="sf-field-hint">Nutzen Sie diesen Link in anderen Portalen (z.B. Airbnb), um Überbuchungen zu vermeiden. Dadurch werden bei uns gebuchte Daten dort automatisch blockiert.</p>
                <div class="sf-ical-input-group">
                    <input type="text" id="sf_ical_export_url" value="<?php echo esc_url($export_url); ?>" readonly>
                    <button type="button" class="sf-3d-btn sf-3d-btn-navy" id="sf_copy_btn" onclick="sfCopyIcal()">Kopieren</button>
                </div>
            </div>
            <?php endif; ?>

            <form id="sf-edit-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sf_process_edit_apartment">
                <input type="hidden" name="apt_id" value="<?php echo $apt_id; ?>">
                <?php wp_nonce_field('sf_edit_apt_action', 'sf_edit_apt_nonce'); ?>

                <div class="sf-status-container">
                    <div class="sf-status-tile <?php echo ($status !== 'draft') ? 'active-online' : ''; ?>" id="tile_online" onclick="sfToggleStatus('online')">
                        <input type="radio" name="apt_status" id="st_online" value="online" <?php checked($status !== 'draft'); ?> style="display:none;">
                        <h4>Online / Aktiv</h4>
                        <p>Das Apartment ist sichtbar und kann von Gästen gebucht werden.</p>
                    </div>
                    <div class="sf-status-tile <?php echo ($status === 'draft') ? 'active-offline' : ''; ?>" id="tile_offline" onclick="sfToggleStatus('offline')">
                        <input type="radio" name="apt_status" id="st_offline" value="offline" <?php checked($status, 'draft'); ?> style="display:none;">
                        <h4>Pausiert / Offline</h4>
                        <p>Das Apartment wird vorübergehend vom Netz genommen.</p>
                    </div>
                </div>

                <h3 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px;">A. Basisdaten & Kontakte</h3>
                
                <div class="sf-form-group">
                    <label>Name der Unterkunft *</label>
                    <input type="text" name="apt_name" value="<?php echo esc_attr($title); ?>" required>
                    <p class="sf-field-hint">Wählen Sie einen aussagekräftigen Namen, z.B. "Modernes Loft am Dom".</p>
                </div>
                
                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Beschreibung</label>
                    <p class="sf-field-hint" style="margin-bottom:10px;">Beschreiben Sie die Highlights Ihrer Unterkunft. Bitte verwenden Sie keine Links.</p>
                    <?php wp_editor($description, 'apt_description', ['media_buttons'=>false, 'textarea_rows'=>8, 'teeny'=>true, 'quicktags'=>false, 'tinymce'=>['toolbar1'=>'bold,italic,bullist,numlist,undo,redo']]); ?>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Hausregeln / Gut zu wissen (House rules)</label>
                    <input type="text" name="apt_house_rules" value="<?php echo esc_attr($house_rules); ?>">
                    <p class="sf-field-hint">Z.B. "Check-in: 15:00–20:00, Keine Partys, Nichtraucher".</p>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Adresse *</label>
                    <input type="text" name="apt_address" value="<?php echo esc_attr($address); ?>" required>
                    <p class="sf-field-hint">Vollständige Adresse des Apartments (Straße, Hausnummer, PLZ, Ort).</p>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 20px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div class="sf-form-group" style="flex:1; margin: 0;">
                        <label>Name des Eigentümers (Owner Name) *</label>
                        <input type="text" name="apt_owner_name" value="<?php echo esc_attr($owner_name); ?>" required>
                        <p class="sf-field-hint">Vor- und Nachname oder Firmenname.</p>
                    </div>
                    <div class="sf-form-group" style="flex:1; margin: 0;">
                        <label>E-Mail des Eigentümers</label>
                        <input type="email" name="apt_owner_email" value="<?php echo esc_attr($owner_email); ?>">
                        <p class="sf-field-hint">Für interne Kommunikation und Belege.</p>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 20px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Name auf dem Klingelschild *</label>
                        <input type="text" name="apt_doorbell" value="<?php echo esc_attr($doorbell); ?>" required>
                        <p class="sf-field-hint">Exakter Name auf dem Klingelschild (wichtig für Gäste).</p>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Kontaktnummer (Apartment) *</label>
                        <input type="text" name="apt_contact_phone" value="<?php echo esc_attr($phone); ?>" required>
                        <p class="sf-field-hint">Telefonnummer für Gäste während des Aufenthalts.</p>
                    </div>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Wohnung ID (Amtliche Registrierung)</label>
                    <input type="text" name="apt_reg_id" value="<?php echo esc_attr($reg_id); ?>">
                    <p class="sf-field-hint">Falls gesetzlich vorgeschrieben, tragen Sie hier die Registrierungsnummer ein.</p>
                </div>

                <h3 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; margin-top: 30px;">B. Preise & Kalender</h3>
                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Preis pro Nacht (€) *</label>
                        <input type="number" name="apt_price" step="0.01" value="<?php echo esc_attr($price); ?>" required>
                        
                        <?php if ($business_model === 'model_a'): ?>
                            <p class="sf-field-hint">Geben Sie Ihren gewünschten <strong>Auszahlungsbetrag (Netto)</strong> pro Nacht an. Stay4Fair kümmert sich um die City-Tax. Sie müssen diesen Betrag lediglich einkommenversteuern.</p>
                        <?php else: ?>
                            <p class="sf-field-hint">Geben Sie Ihren gewünschten Endpreis an. <strong>Wichtig:</strong> Dieser Preis muss bereits unsere <strong><?php echo esc_html($commission_rate); ?>% Provision</strong> sowie die lokale City-Tax (Tourismusabgabe) enthalten.</p>
                        <?php endif; ?>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Min. Aufenthalt (Nächte) *</label>
                        <input type="number" name="apt_min_stay" value="<?php echo esc_attr($min_stay); ?>" required>
                        <p class="sf-field-hint">Die Mindestanzahl an aufeinanderfolgenden Nächten, die ein Gast bei Ihnen buchen muss.</p>
                    </div>
                </div>

                <div class="sf-form-group" style="margin-top: 25px; background: #fdf8ed; border: 2px solid #E0B849; padding: 20px; border-radius: 10px;">
                    <label style="font-size: 16px; color: #082567; font-weight: bold;">iCal-Synchronisation (Externe Kalender)</label>
                    <p class="sf-field-hint" style="margin-bottom: 15px; color:#333;">
                        <strong>Was ist iCal und warum ist es wichtig?</strong><br>
                        iCal ist ein Format, das Ihre Buchungen automatisch mit anderen Plattformen (wie Airbnb, Booking.com oder VRBO) synchronisiert.
                    </p>

                    <div id="ical-inputs-container">
                        <?php foreach ($ical_import_urls as $url): ?>
                            <div style="display:flex; margin-bottom:10px;">
                                <input type="text" class="sf-ical-dynamic-input" value="<?php echo esc_attr($url); ?>" placeholder="https://... (z.B. von Airbnb)" style="flex:1; padding:10px; border:1px solid #cbd5e1; border-radius:6px;">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="add-ical-btn" class="sf-3d-btn sf-3d-btn-navy" style="font-size: 12px; padding: 6px 14px; margin-top: 5px;">
                        + Weiteren iCal-Link hinzufügen
                    </button>
                    
                    <input type="hidden" name="apt_ical" id="apt_ical_hidden" value="">
                </div>
                
                <div class="sf-form-group" style="margin-top: 25px;">
                    <label>Stornierungsbedingungen *</label>
                    <p class="sf-field-hint" style="margin-bottom: 10px;">Hier legen Sie fest, unter welchen Bedingungen Gäste ihre Buchung stornieren können. Bei "Flexibel" können Sie bestimmen, bis wie viele Tage vor Anreise die Stornierung kostenfrei ist.</p>
                    <input type="hidden" name="apt_cancellation" id="apt_cancellation_val" value="<?php echo ($cancel_pol === 'non_refundable') ? 'non_refundable' : 'flexible'; ?>">
                    <div class="sf-cards-container">
                        <div class="sf-policy-card <?php echo ($cancel_pol !== 'non_refundable') ? 'active' : ''; ?>" id="card_flexible" onclick="sfSetPolicy('flexible')">
                            <h4 style="margin:0 0 5px 0;">Flexibel</h4>
                            <p>Kostenlose Stornierung bis <input type="number" name="apt_flex_days" value="<?php echo esc_attr($cancel_days); ?>" class="sf-policy-input" onclick="event.stopPropagation()"> Tage vor Anreise.</p>
                        </div>
                        <div class="sf-policy-card <?php echo ($cancel_pol === 'non_refundable') ? 'active' : ''; ?>" id="card_non_refundable" onclick="sfSetPolicy('non_refundable')">
                            <h4 style="margin:0 0 5px 0;">Nicht erstattbar</h4>
                            <p>Der Gast zahlt bei Stornierung den vollen Preis.</p>
                        </div>
                    </div>
                </div>

                <div class="sf-consent-block" style="background: #fdf8ed; padding: 15px; border-radius: 8px; border: 1px solid #E0B849; margin-top: 25px;">
                    <label style="display:flex; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="apt_loyalty" value="1" <?php checked($loyalty, 1); ?>>
                        <div>
                            <strong>Am "Fair Return" Programm teilnehmen (-10%)</strong>
                            <p class="sf-field-hint">Stammgäste erhalten 10% Rabatt bei erneuter Buchung. Unsere Provision sinkt dann ebenfalls auf 10%.</p>
                        </div>
                    </label>
                </div>

                <h3 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; margin-top: 30px;">C. Kapazität & Ausstattung</h3>
                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Max. Erwachsene *</label>
                        <input type="number" name="apt_adults" value="<?php echo esc_attr($adults); ?>" required>
                        <p class="sf-field-hint"><strong>Tipp:</strong> Sie können 1 Bett/Sofa als 1 Person zählen oder ein Doppelbett für 2 Personen berechnen.</p>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Max. Kinder</label>
                        <input type="number" name="apt_children" value="<?php echo esc_attr($children); ?>">
                        <p class="sf-field-hint">Lassen Sie dieses Feld auf 0, wenn Ihre Unterkunft nicht speziell für Kinder geeignet ist.</p>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Kategorie *</label>
                        <select name="apt_category" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($all_cats as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array($cat->term_id, $curr_cats)); ?>><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sf-field-hint">Dient zur Gruppierung auf der Webseite.</p>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Apartment Typ *</label>
                        <select name="apt_attribute_type" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($all_attrs as $type): ?>
                                <option value="<?php echo esc_attr($type->term_id); ?>" <?php selected(in_array($type->term_id, $curr_attrs)); ?>><?php echo esc_html($type->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sf-field-hint">Wählen Sie die treffendste Kategorie für Ihre Unterkunft.</p>
                    </div>
                </div>

                <div class="sf-form-group" style="margin-top: 20px;">
                    <label>Ausstattung (Amenities)</label>
                    <p class="sf-field-hint" style="margin-bottom: 10px;">Markieren Sie alle Annehmlichkeiten, die in Ihrer Unterkunft zur Verfügung stehen.</p>
                    <div class="sf-checkbox-grid">
                        <?php foreach ($all_amen as $amenity): 
                            $name_en = trim($amenity->name);
                            $name_de = isset($amenity_translations[$name_en]) ? $amenity_translations[$name_en] : $name_en;
                        ?>
                            <label class="sf-checkbox-item">
                                <input type="checkbox" name="apt_amenities[]" value="<?php echo esc_attr($amenity->term_id); ?>" <?php checked(in_array($amenity->term_id, $curr_amen)); ?>>
                                <?php echo esc_html($name_de); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <h3 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; margin-top: 30px;">D. Auszahlung & DAC7</h3>
                <p class="sf-field-hint" style="margin-bottom:15px; color:#082567; font-weight:500;">
                    Auszahlungen erfolgen nach Abreise der Gäste, sofern keine Reklamationen vorliegen, gemäß unseren 
                    <a href="https://stay4fair.com/owner-terms-agb/" target="_blank" style="text-decoration:underline;">AGB</a>.
                </p>
                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Kontoinhaber *</label>
                        <input type="text" name="apt_bank_name" value="<?php echo esc_attr($bank_name); ?>" required>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>IBAN *</label>
                        <input type="text" name="apt_bank_iban" value="<?php echo esc_attr($iban); ?>" required>
                    </div>
                </div>
                <div class="sf-form-group" style="margin-top:15px;">
                    <label>Steuernummer (Für DAC7)</label>
                    <input type="text" name="apt_tax_id" value="<?php echo esc_attr($tax_id); ?>">
                    <p class="sf-field-hint">Gemäß EU-Richtlinie (DAC7) benötigt. Details in unseren <a href="https://stay4fair.com/owner-terms-agb/" target="_blank">AGB</a>.</p>
                </div>

                <h3 style="color:#082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; margin-top: 30px;">E. Fotos Verwalten</h3>
                <p class="sf-field-hint">Ziehen Sie die Bilder, um die Reihenfolge zu ändern. Das erste Foto ist das Hauptbild.</p>
                
                <input type="hidden" name="sf_gallery_order" id="sf_gallery_order" value="<?php echo esc_attr(implode(',', $current_images)); ?>">
                <input type="hidden" name="sf_deleted_images" id="sf_deleted_images" value="">

                <div class="sf-gallery-grid" id="sf-gallery-grid">
                    <?php foreach ($current_images as $img_id): 
                        $img_url = wp_get_attachment_image_url($img_id, 'medium');
                        if (!$img_url) continue;
                    ?>
                        <div class="sf-gallery-item" draggable="true" data-id="<?php echo esc_attr($img_id); ?>">
                            <img src="<?php echo esc_url($img_url); ?>">
                            <div class="sf-gallery-badge">Titelbild</div>
                            <button type="button" class="sf-gallery-delete" onclick="sfRemoveImage(this, <?php echo esc_attr($img_id); ?>)">LÖSCHEN</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="sf-drop-zone" class="sf-drop-zone" onclick="document.getElementById('apt_photos').click()">
                    <span class="sf-drop-zone-icon">📸</span>
                    <p>Zusätzliche Fotos hochladen</p>
                    <input type="file" name="apt_photos[]" id="apt_photos" multiple accept="image/*" style="display: none;">
                </div>
                <div id="file-preview-list" class="sf-file-list"></div>

                <div style="margin-top: 40px; text-align: right; border-top:1px solid #e2e8f0; padding-top:20px;">
                    <button type="submit" class="sf-3d-btn" style="font-size: 16px;">Änderungen speichern</button>
                </div>
            </form>
        </div>

        <script>
        function sfSetPolicy(t) { 
            document.getElementById('apt_cancellation_val').value = t; 
            document.querySelectorAll('.sf-policy-card').forEach(c=>c.classList.remove('active')); 
            document.getElementById('card_'+t).classList.add('active'); 
        }
        function sfToggleStatus(val) {
            const tOnline = document.getElementById('tile_online');
            const tOffline = document.getElementById('tile_offline');
            const rOnline = document.getElementById('st_online');
            const rOffline = document.getElementById('st_offline');

            tOnline.classList.remove('active-online');
            tOffline.classList.remove('active-offline');

            if(val === 'online') {
                rOnline.checked = true;
                tOnline.classList.add('active-online');
            } else {
                rOffline.checked = true;
                tOffline.classList.add('active-offline');
            }
        }
        function sfCopyIcal() { 
            var c = document.getElementById("sf_ical_export_url"); c.select(); document.execCommand("copy"); 
            var b = document.getElementById("sf_copy_btn"); b.innerText = "Kopiert! ✓"; setTimeout(()=>b.innerText="Kopieren", 2000); 
        }
        function sfRemoveImage(b, id) { 
            if(!confirm('Foto wirklich löschen?')) return; 
            b.closest('.sf-gallery-item').remove(); 
            var d = document.getElementById('sf_deleted_images'); d.value += (d.value ? ',' : '') + id; 
            sfUpdateGalleryOrder(); 
        }
        function sfUpdateGalleryOrder() { 
            var i = document.querySelectorAll('.sf-gallery-item'); 
            var o = Array.from(i).map(x => x.getAttribute('data-id')); 
            document.getElementById('sf_gallery_order').value = o.join(','); 
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const grid = document.getElementById('sf-gallery-grid');
            let draggedItem = null;
            if(grid) {
                grid.addEventListener('dragstart', e => { if(e.target.classList.contains('sf-gallery-item')) { draggedItem = e.target; e.target.style.opacity = '0.5'; } });
                grid.addEventListener('dragend', e => { if(draggedItem) { draggedItem.style.opacity = '1'; sfUpdateGalleryOrder(); draggedItem = null; } });
                grid.addEventListener('dragover', e => e.preventDefault());
                grid.addEventListener('drop', e => { 
                    e.preventDefault(); 
                    const target = e.target.closest('.sf-gallery-item');
                    if (target && target !== draggedItem) { grid.insertBefore(draggedItem, target); } 
                    else if (!target && draggedItem) { grid.appendChild(draggedItem); }
                    sfUpdateGalleryOrder();
                });
            }

            const dropZone = document.getElementById('sf-drop-zone');
            const fileInput = document.getElementById('apt_photos');
            const previewList = document.getElementById('file-preview-list');
            
            if(dropZone && fileInput) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, x => { x.preventDefault(); x.stopPropagation(); }));
                
                dropZone.addEventListener('drop', e => { 
                    fileInput.files = e.dataTransfer.files; 
                    fileInput.dispatchEvent(new Event('change')); 
                });

                fileInput.addEventListener('change', function() {
                    previewList.innerHTML = '';
                    Array.from(this.files).forEach(f => {
                        let s = document.createElement('span'); s.className = 'sf-file-item'; s.textContent = f.name; previewList.appendChild(s);
                    });
                });
            }

            // Multi-iCal Script
            const icalContainer = document.getElementById("ical-inputs-container");
            const addIcalBtn = document.getElementById("add-ical-btn");
            const hiddenIcalInput = document.getElementById("apt_ical_hidden");
            const editForm = document.getElementById("sf-edit-form");

            if (addIcalBtn && icalContainer) {
                addIcalBtn.addEventListener("click", function() {
                    const div = document.createElement("div");
                    div.style.display = "flex";
                    div.style.marginBottom = "10px";
                    
                    const input = document.createElement("input");
                    input.type = "text";
                    input.className = "sf-ical-dynamic-input";
                    input.style.flex = "1";
                    input.style.padding = "10px";
                    input.style.border = "1px solid #cbd5e1";
                    input.style.borderRadius = "6px";
                    input.placeholder = "https://... (z.B. von Airbnb)";
                    
                    div.appendChild(input);
                    icalContainer.appendChild(div);
                });
            }

            if (editForm && hiddenIcalInput) {
                editForm.addEventListener("submit", function(e) {
                    const inputs = icalContainer.querySelectorAll(".sf-ical-dynamic-input");
                    let urls = [];
                    let hasError = false;
                    
                    inputs.forEach(input => {
                        let val = input.value.trim();
                        if (val !== "") {
                            if (!val.startsWith('http') && !val.startsWith('webcal')) {
                                alert('Fehler: Die iCal-URL muss mit http://, https:// oder webcal:// beginnen.');
                                hasError = true;
                                input.style.borderColor = '#ef4444';
                            } else {
                                urls.push(val);
                                input.style.borderColor = '#cbd5e1';
                            }
                        }
                    });
                    
                    if (hasError) {
                        e.preventDefault();
                        return;
                    }
                    
                    hiddenIcalInput.value = urls.join(",");
                });
            }
        });
        </script>
        <?php return ob_get_clean();
    }
}