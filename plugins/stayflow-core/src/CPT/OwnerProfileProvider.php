<?php

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.4.0
 * RU: Провайдер страницы профиля владельца.
 * - [NEW]: Встроен DSGVO-совместимый попап для Паузы / Полного удаления аккаунта.
 * EN: Owner profile page provider. DSGVO-compliant popup for Pause / Full deletion.
 */
final class OwnerProfileProvider
{
    public function register(): void
    {
        add_shortcode('sf_owner_profile', [$this, 'renderProfile']);
        
        add_action('show_user_profile', [$this, 'addAdminUserFields']);
        add_action('edit_user_profile', [$this, 'addAdminUserFields']);
        
        add_action('personal_options_update', [$this, 'saveAdminUserFields']);
        add_action('edit_user_profile_update', [$this, 'saveAdminUserFields']);
    }

    public static function isActionRequired(int $userId): bool
    {
        $iban = get_user_meta($userId, 'bsbt_iban', true);
        $steuernummer = get_user_meta($userId, 'bsbt_tax_number', true);
        
        return empty(trim((string)$iban)) || empty(trim((string)$steuernummer));
    }

    public function addAdminUserFields(\WP_User $user): void
    {
        $altPhone = get_user_meta($user->ID, 'bsbt_alt_phone', true);
        $accType = get_user_meta($user->ID, 'sf_account_type', true) ?: 'private';
        $companyName = get_user_meta($user->ID, 'sf_company_name', true);
        $vatId = get_user_meta($user->ID, 'sf_vat_id', true);
        $companyReg = get_user_meta($user->ID, 'sf_company_reg', true);

        echo '<h3>StayFlow: Zusätzliche Owner-Daten</h3>';
        echo '<table class="form-table">';
        echo '<tr><th><label>Account Typ</label></th><td><input type="text" value="' . esc_attr(ucfirst($accType)) . '" class="regular-text" readonly></td></tr>';
        echo '<tr><th><label for="bsbt_alt_phone">Alternative Telefonnummer</label></th><td><input type="text" name="bsbt_alt_phone" id="bsbt_alt_phone" value="' . esc_attr($altPhone) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="sf_company_name">Firmenname</label></th><td><input type="text" name="sf_company_name" id="sf_company_name" value="' . esc_attr($companyName) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="sf_vat_id">USt-IdNr. (VAT ID)</label></th><td><input type="text" name="sf_vat_id" id="sf_vat_id" value="' . esc_attr($vatId) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="sf_company_reg">Handelsregisternummer</label></th><td><input type="text" name="sf_company_reg" id="sf_company_reg" value="' . esc_attr($companyReg) . '" class="regular-text"></td></tr>';
        echo '</table>';
    }

    public function saveAdminUserFields(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) return;
        
        if (isset($_POST['bsbt_alt_phone'])) update_user_meta($userId, 'bsbt_alt_phone', sanitize_text_field($_POST['bsbt_alt_phone']));
        if (isset($_POST['sf_company_name'])) update_user_meta($userId, 'sf_company_name', sanitize_text_field($_POST['sf_company_name']));
        if (isset($_POST['sf_vat_id'])) update_user_meta($userId, 'sf_vat_id', sanitize_text_field($_POST['sf_vat_id']));
        if (isset($_POST['sf_company_reg'])) update_user_meta($userId, 'sf_company_reg', sanitize_text_field($_POST['sf_company_reg']));
    }

    public function renderProfile(): string
    {
        if (!is_user_logged_in()) return '<div class="sf-alert">Bitte loggen Sie sich ein.</div>';

        $user = wp_get_current_user();
        $userId = $user->ID;

        $accType     = get_user_meta($userId, 'sf_account_type', true) ?: 'private';
        $firstName   = get_user_meta($userId, 'first_name', true) ?: $user->first_name;
        $lastName    = get_user_meta($userId, 'last_name', true) ?: $user->last_name;
        $email       = $user->user_email;
        $phone       = get_user_meta($userId, 'billing_phone', true);
        
        $altPhone    = get_user_meta($userId, 'bsbt_alt_phone', true);
        $bankName    = get_user_meta($userId, 'bsbt_account_holder', true) ?: "$firstName $lastName";
        $iban        = get_user_meta($userId, 'bsbt_iban', true);
        $steuerId    = get_user_meta($userId, 'bsbt_tax_number', true);

        $address     = get_user_meta($userId, 'billing_address_1', true);
        $postcode    = get_user_meta($userId, 'billing_postcode', true);
        $city        = get_user_meta($userId, 'billing_city', true);

        $companyName = get_user_meta($userId, 'sf_company_name', true);
        $vatId       = get_user_meta($userId, 'sf_vat_id', true);
        $companyReg  = get_user_meta($userId, 'sf_company_reg', true);
        
        $avatarId    = get_user_meta($userId, 'stayflow_avatar_id', true);
        $avatarUrl   = '';
        if ($avatarId) {
            $avatarUrl = wp_get_attachment_image_url((int)$avatarId, 'thumbnail');
        }

        $actionRequired = self::isActionRequired($userId);

        ob_start();
        ?>
        <style>
            .sf-profile-wrap { max-width: 900px; margin: 0 auto; font-family: 'Segoe UI', Roboto, sans-serif; color: #1e293b; }
            .sf-alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
            .sf-alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }
            .sf-alert-success { background: #f0fdf4; color: #166534; border: 1px solid #4ade80; }
            .sf-alert-warning { background: #fffbeb; color: #b45309; border: 1px solid #fbbf24; }
            .sf-profile-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
            .sf-profile-card h3 { color: #082567; margin: 0 0 5px 0; font-size: 20px; font-weight: 700; border-bottom: 2px solid #E0B849; padding-bottom: 10px; }
            .sf-field-hint { font-size: 12px; color: #64748b; margin: 5px 0 15px 0; line-height: 1.4; }
            .sf-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; }
            @media(max-width: 768px) { .sf-form-grid { grid-template-columns: 1fr; } }
            .sf-form-group { display: flex; flex-direction: column; }
            .sf-form-group label { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 5px; }
            
            .sf-form-group input[type="text"], 
            .sf-form-group input[type="email"], 
            .sf-form-group input[type="password"] { padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-size: 14px; transition: 0.2s; width: 100%; box-sizing: border-box; }
            .sf-form-group input:focus { border-color: #082567; box-shadow: 0 0 0 3px rgba(8,37,103,0.1); }
            
            .sf-pwd-wrapper { position: relative; display: flex; align-items: center; }
            .sf-pwd-wrapper input { padding-right: 40px !important; }
            .sf-pwd-toggle { position: absolute; right: 10px; cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; transition: color 0.2s; }
            .sf-pwd-toggle:hover { color: #082567; }

            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 14px; }
            .sf-3d-btn:hover { transform: translateY(-2px) !important; background-color: #082567 !important; color: #E0B849 !important; }
            .btn-danger { background-color: #ef4444 !important; color: #fff !important; background-image: none !important; }
            .btn-danger:hover { background-color: #991b1b !important; color: #fff !important; }
            .btn-submit { margin-top: 20px; width: 100%; }

            /* RU: Стили для модального окна */
            .sf-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(4px); }
            .sf-modal-overlay.active { opacity: 1; pointer-events: all; }
            .sf-modal-box { background: #fff; padding: 30px; border-radius: 12px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: translateY(20px); transition: transform 0.3s; }
            .sf-modal-overlay.active .sf-modal-box { transform: translateY(0); }
            .sf-modal-close { float: right; background: none; border: none; font-size: 24px; cursor: pointer; color: #94a3b8; }
            .sf-modal-close:hover { color: #000; }
        </style>

        <div class="sf-profile-wrap">
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="sf-alert sf-alert-success">✅ Profil erfolgreich aktualisiert.</div>
            <?php endif; ?>
            <?php if (isset($_GET['security_updated'])): ?>
                <div class="sf-alert sf-alert-success">🔐 Sicherheitseinstellungen erfolgreich gespeichert.</div>
            <?php endif; ?>
            <?php if (isset($_GET['paused'])): ?>
                <div class="sf-alert sf-alert-warning">⏸️ <strong>Ihr Account ist pausiert.</strong> Ihre Apartments wurden vom Netz genommen (Offline). Sie können diese im Dashboard jederzeit wieder aktivieren.</div>
            <?php endif; ?>
            <?php if (isset($_GET['security_error'])): ?>
                <div class="sf-alert sf-alert-danger">❌ Fehler: Bitte prüfen Sie Ihr aktuelles Passwort oder die eingegebene E-Mail.</div>
            <?php endif; ?>
            <?php if (isset($_GET['delete_error']) && $_GET['delete_error'] === 'active_bookings'): ?>
                <div class="sf-alert sf-alert-danger"><strong>⚠️ Aktion blockiert!</strong><br>Eine vollständige Löschung des Accounts ist nicht möglich, da Sie noch aktive zukünftige Buchungen haben. Bitte stornieren Sie diese zuerst oder kontaktieren Sie unseren Support.</div>
            <?php endif; ?>
            <?php if ($actionRequired): ?>
                <div class="sf-alert sf-alert-warning" style="display:flex; align-items:center; gap:10px;"><span style="font-size:24px;">🔴</span><div><strong>Aktion erforderlich (DAC7)</strong><br>Bitte füllen Sie Ihre Steuer-ID (Steuernummer) und IBAN aus, um Auszahlungen zu erhalten.</div></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sf_process_owner_profile">
                <input type="hidden" name="profile_action" value="save_profile">
                <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>

                <div class="sf-profile-card">
                    <h3>Persönliche Daten & Kontakt</h3>
                    <p class="sf-field-hint">Ihre primären Kontaktdaten. Account Typ: <strong><?php echo ucfirst($accType); ?></strong></p>
                    
                    <div class="sf-form-grid">
                        
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Profilbild (Avatar)</label>
                            <?php if ($avatarUrl): ?>
                                <div style="margin-bottom: 10px;">
                                    <img src="<?php echo esc_url($avatarUrl); ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="owner_avatar" accept="image/jpeg, image/png, image/webp" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100%; box-sizing: border-box; background: #f8fafc;">
                            <p class="sf-field-hint">Laden Sie ein professionelles Foto hoch (JPG/PNG). Dieses Profilbild ist öffentlich und wird potenziellen Gästen auf der Seite Ihres Apartments angezeigt. Ein sympathisches Foto schafft Vertrauen. Das Hochladen ist jedoch optional.</p>
                        </div>

                        <div class="sf-form-group">
                            <label>Vorname *</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr($firstName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Nachname *</label>
                            <input type="text" name="last_name" value="<?php echo esc_attr($lastName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Telefonnummer *</label>
                            <input type="text" name="phone" value="<?php echo esc_attr($phone); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Alternative Telefonnummer</label>
                            <input type="text" name="alt_phone" value="<?php echo esc_attr($altPhone); ?>">
                        </div>
                    </div>

                    <h4 style="margin: 25px 0 10px 0; color:#475569;">Rechnungsadresse</h4>
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Straße & Hausnummer *</label>
                            <input type="text" name="address" value="<?php echo esc_attr($address); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Postleitzahl *</label>
                            <input type="text" name="postcode" value="<?php echo esc_attr($postcode); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Stadt *</label>
                            <input type="text" name="city" value="<?php echo esc_attr($city); ?>" required>
                        </div>
                    </div>
                </div>

                <?php if ($accType === 'commercial'): ?>
                <div class="sf-profile-card">
                    <h3>Unternehmensdaten</h3>
                    <p class="sf-field-hint">Zusätzliche Daten für gewerbliche Gastgeber.</p>
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Firmenname *</label>
                            <input type="text" name="company_name" value="<?php echo esc_attr($companyName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>USt-IdNr. (VAT ID) *</label>
                            <input type="text" name="vat_id" value="<?php echo esc_attr($vatId); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Handelsregisternummer (Optional)</label>
                            <input type="text" name="company_reg" value="<?php echo esc_attr($companyReg); ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="sf-profile-card" style="border-color: <?php echo $actionRequired ? '#fbbf24' : '#e2e8f0'; ?>;">
                    <h3>Bank & Steuern (DAC7)</h3>
                    <p class="sf-field-hint">Pflichtangaben zur Abwicklung Ihrer Auszahlungen und gemäß EU-Richtlinie DAC7.</p>
                    <div class="sf-form-grid">
                        <div class="sf-form-group">
                            <label>Kontoinhaber *</label>
                            <input type="text" name="bank_name" value="<?php echo esc_attr($bankName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>IBAN (Standard) *</label>
                            <input type="text" name="iban" value="<?php echo esc_attr($iban); ?>" required style="<?php echo empty($iban) ? 'border-color:#ef4444;' : ''; ?>">
                        </div>
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Steuernummer (Steuer-ID) *</label>
                            <input type="text" name="steuernummer" value="<?php echo esc_attr($steuerId); ?>" required style="<?php echo empty($steuerId) ? 'border-color:#ef4444;' : ''; ?>">
                            <p class="sf-field-hint" style="margin-top:4px;">Ihre persönliche Identifikationsnummer (IdNr.) oder Steuernummer des Unternehmens.</p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="sf-3d-btn btn-submit">Profil Speichern</button>
            </form>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 40px;" autocomplete="off">
                <input type="hidden" name="action" value="sf_process_owner_profile">
                <input type="hidden" name="profile_action" value="save_security">
                <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>

                <div class="sf-profile-card">
                    <h3>Sicherheit & Login</h3>
                    <p class="sf-field-hint">Um E-Mail oder Passwort zu ändern, müssen Sie Ihr aktuelles Passwort bestätigen.</p>
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Aktuelle E-Mail Adresse</label>
                            <input type="email" name="user_email" value="<?php echo esc_attr($email); ?>" required>
                        </div>
                        
                        <div class="sf-form-group">
                            <label>Neues Passwort (Optional)</label>
                            <div class="sf-pwd-wrapper">
                                <input type="password" id="sf_new_pass" name="new_pass" placeholder="Leer lassen, wenn keine Änderung" autocomplete="new-password" data-lpignore="true">
                                <span class="sf-pwd-toggle" onclick="sfTogglePassword('sf_new_pass', this)">
                                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </span>
                            </div>
                        </div>

                        <div class="sf-form-group">
                            <label>Neues Passwort bestätigen</label>
                            <div class="sf-pwd-wrapper">
                                <input type="password" id="sf_new_pass_confirm" name="new_pass_confirm" placeholder="Passwort wiederholen" autocomplete="new-password" data-lpignore="true">
                                <span class="sf-pwd-toggle" onclick="sfTogglePassword('sf_new_pass_confirm', this)">
                                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </span>
                            </div>
                        </div>

                        <div class="sf-form-group" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px;">
                            <label style="color:#991b1b;">Aktuelles Passwort (Pflichtfeld zur Bestätigung) *</label>
                            <div class="sf-pwd-wrapper">
                                <input type="password" id="sf_current_pass" name="current_pass" required autocomplete="current-password" data-lpignore="true">
                                <span class="sf-pwd-toggle" onclick="sfTogglePassword('sf_current_pass', this)">
                                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </span>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="sf-3d-btn btn-submit">Sicherheitseinstellungen speichern</button>
                </div>
            </form>

            <div class="sf-profile-card" style="border-color: #fca5a5; background: #fef2f2; margin-top: 40px;">
                <h3 style="color: #991b1b; border-color: #fca5a5;">Gefahrenzone (Danger Zone)</h3>
                <p class="sf-field-hint" style="color: #7f1d1d;">Hier können Sie Ihren Account temporär pausieren oder vollständig löschen (DSGVO-konform).</p>
                <button type="button" onclick="sfOpenModal()" class="sf-3d-btn btn-danger" style="margin-top: 10px;">Account verwalten</button>
            </div>

        </div>

        <div id="sf-delete-modal" class="sf-modal-overlay">
            <div class="sf-modal-box">
                <button class="sf-modal-close" onclick="sfCloseModal()">&times;</button>
                <h2 style="color:#082567; margin-top:0;">Account verwalten</h2>
                <p style="color:#475569; font-size:14px; margin-bottom:20px;">Bitte wählen Sie, wie Sie fortfahren möchten. Bei beiden Optionen werden Ihre Apartments sofort vom Netz genommen.</p>
                
                <div style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:15px; border-left:4px solid #f59e0b;">
                    <h4 style="margin:0 0 5px 0; color:#b45309;">⏸️ Account stilllegen (Pausieren)</h4>
                    <p style="margin:0; font-size:12px; color:#64748b;">Ihre Objekte gehen offline (Draft), aber Ihr Account bleibt bestehen. Sie können sich weiterhin einloggen und Ihre Objekte später wieder aktivieren.</p>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                        <input type="hidden" name="action" value="sf_process_owner_profile">
                        <input type="hidden" name="profile_action" value="pause_account">
                        <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>
                        <button type="submit" class="sf-3d-btn" style="width:100%; padding:8px;">Jetzt pausieren</button>
                    </form>
                </div>

                <div style="background:#fef2f2; padding:15px; border-radius:8px; border-left:4px solid #ef4444;">
                    <h4 style="margin:0 0 5px 0; color:#991b1b;">🗑️ Komplett löschen (DSGVO)</h4>
                    <p style="margin:0; font-size:12px; color:#7f1d1d;">Ihre persönlichen Daten werden gelöscht. Nur wenn Sie <b>keine</b> aktiven Buchungen mehr haben möglich. Dieser Vorgang ist unwiderruflich!</p>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;" onsubmit="return confirm('Sind Sie sicher? Diese Aktion kann nicht rückgängig gemacht werden!');">
                        <input type="hidden" name="action" value="sf_process_owner_profile">
                        <input type="hidden" name="profile_action" value="delete_account">
                        <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>
                        <button type="submit" class="sf-3d-btn btn-danger" style="width:100%; padding:8px;">Endgültig löschen</button>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function sfTogglePassword(inputId, iconSpan) {
                const input = document.getElementById(inputId);
                if (input.type === 'password') {
                    input.type = 'text';
                    iconSpan.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                } else {
                    input.type = 'password';
                    iconSpan.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
                }
            }

            document.querySelector('input[name="new_pass_confirm"]').addEventListener('input', function(e) {
                const p1 = document.querySelector('input[name="new_pass"]').value;
                if (p1 && e.target.value !== p1) {
                    e.target.setCustomValidity("Passwörter stimmen nicht überein");
                } else {
                    e.target.setCustomValidity("");
                }
            });

            // Modal Logic
            function sfOpenModal() { document.getElementById('sf-delete-modal').classList.add('active'); }
            function sfCloseModal() { document.getElementById('sf-delete-modal').classList.remove('active'); }
            document.getElementById('sf-delete-modal').addEventListener('click', function(e) {
                if(e.target === this) sfCloseModal();
            });
        </script>
        <?php
        return ob_get_clean();
    }
}