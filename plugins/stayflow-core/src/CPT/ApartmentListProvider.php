<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.0.6
 * RU: Вывод списка квартир.
 * - [FIX]: Отцентрирован текст в кнопке запроса и крестик закрытия в попапе.
 * - [UPDATE]: Тексты для сравнения моделей теперь тянутся из ContentRegistry (stayflow_registry_content).
 * EN: Apartment list rendering. UI fixes for modal and dynamic content registry integration.
 */
final class ApartmentListProvider
{
    // ==========================================================================
    // REGISTER HOOKS / РЕГИСТРАЦИЯ ХУКОВ
    // ==========================================================================
    public function register(): void
    {
        add_shortcode('sf_owner_apartments_list', [$this, 'renderList']);
        add_action('wp_ajax_sf_request_model_change', [$this, 'handleModelChangeRequest']);
    }

    // ==========================================================================
    // RENDER LIST / ВЫВОД СПИСКА
    // ==========================================================================
    public function renderList(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte loggen Sie sich ein.</p>';
        }

        $userId = get_current_user_id();
        
        // RU: Проверка на юр. лицо (B2B)
        // EN: Check for legal entity (B2B)
        $owner_type = get_user_meta($userId, '_sf_owner_type', true) ?: 'private';
        $company_name = get_user_meta($userId, 'sf_company_name', true);
        $is_b2b = ($owner_type !== 'private' || !empty($company_name));
        
        // RU: Ищем квартиры, где юзер либо автор (post_author), либо назначен через bsbt_owner_id
        global $wpdb;
        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %d)
        ", $userId, $userId));

        $apartments = [];
        if (!empty($apt_ids)) {
            $apartments = get_posts([
                'post_type'      => 'mphb_room_type',
                'post__in'       => $apt_ids,
                'posts_per_page' => -1,
                'post_status'    => 'any'
            ]);
        }

        $nonce = wp_create_nonce('sf_model_change_nonce');
        $ajax_url = admin_url('admin-ajax.php');

        // RU: Загружаем тексты из ContentRegistry
        // EN: Load texts from ContentRegistry
        $content_reg = get_option('stayflow_registry_content', []);
        
        // Fallbacks (matching ContentRegistry defaults)
        $mod_a_title = $content_reg['model_a_compare_title'] ?? '🔵 Modell A (Direkt)';
        $mod_b_title = $content_reg['model_b_compare_title'] ?? '🟡 Modell B (Vermittlung)';
        $mod_a_desc  = $content_reg['model_a_compare_desc'] ?? '<strong>Stay4Fair zahlt die City-Tax</strong>.<br><br>Sie geben nur Ihren Netto-Auszahlungswunsch an. Wir kümmern uns um den Endpreis. <em>(Für die Einkommensteuer bleiben Sie selbst verantwortlich.)</em><br><br><em>Ideal für weniger Bürokratie.</em>';
        $mod_b_desc  = $content_reg['model_b_compare_desc'] ?? '<strong>Sie zahlen die City-Tax & Steuern selbst</strong>.<br><br>Sie bestimmen den Brutto-Endpreis für den Gast. Stay4Fair berechnet 15% Provision.<br><br><em>Volle Preiskontrolle für den Vermieter.</em>';
        $mod_footer  = $content_reg['model_compare_footer'] ?? 'Der Wechsel des Modells wird von unserem Team geprüft. Laufende Buchungen bleiben unberührt.';

        ob_start();
        ?>
        <style>
            .sf-apt-list-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 1140px; margin: 0 auto; padding: 20px 0; }
            .sf-apt-list-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #E0B849; padding-bottom: 15px; margin-bottom: 25px; }
            .sf-apt-list-header h2 { color: #082567; margin: 0; font-size: 24px; font-weight: 700; }
            
            /* RU: Добавлен justify-content: center; text-align: center; для идеального выравнивания текста */
            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; justify-content: center; text-align: center; gap: 8px; padding: 12px 24px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 14px; text-decoration: none; }
            .sf-3d-btn::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; }
            .sf-3d-btn:hover { background-color: #082567 !important; color: #E0B849 !important; transform: translateY(-2px) !important; }
            
            .sf-3d-btn-navy { background-color: #082567 !important; color: #E0B849 !important; }
            .sf-3d-btn-navy:hover { background-color: #E0B849 !important; color: #082567 !important; }

            .sf-apt-card { display: flex; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: all 0.3s; position: relative; }
            .sf-apt-card:hover { border-color: #cbd5e1; box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
            .sf-apt-img { width: 240px; min-height: 160px; background-color: #f1f5f9; background-size: cover; background-position: center; border-right: 1px solid #e2e8f0; }
            .sf-apt-info { padding: 20px 25px; flex: 1; display: flex; flex-direction: column; justify-content: center; }
            .sf-apt-title { font-size: 20px; font-weight: 700; color: #082567; margin: 0 0 8px 0; padding-right: 320px; }
            .sf-apt-meta { font-size: 14px; color: #64748b; margin: 0 0 12px 0; }
            
            .sf-status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
            .sf-status-publish { background-color: #dcfce7; color: #0f766e; }
            .sf-status-pending { background-color: #fef9c3; color: #b45309; }
            .sf-status-draft { background-color: #f1f5f9; color: #64748b; }

            .sf-model-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 8px; }
            .sf-model-a { background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
            .sf-model-b { background-color: #fef9c3; color: #ca8a04; border: 1px solid #fef08a; }

            .sf-card-actions { position: absolute; top: 20px; right: 20px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; width: 300px; }
            .sf-action-btn { background: #f8fafc; color: #082567; border: 1px solid #cbd5e1; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; transition: 0.2s; display: flex; align-items: center; gap: 5px; cursor: pointer; }
            .sf-action-btn:hover { background: #082567; color: #fff; border-color: #082567; }
            .sf-action-model { background: #fff; color: #64748b; border: 1px dashed #cbd5e1; }
            .sf-action-model:hover { border-style: solid; border-color: #E0B849; color: #082567; }

            /* Modal Styles */
            .sf-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(8, 37, 103, 0.6); backdrop-filter: blur(5px); display: none; justify-content: center; align-items: center; z-index: 9999; opacity: 0; transition: opacity 0.3s; }
            .sf-modal-overlay.active { display: flex; opacity: 1; }
            .sf-modal-content { background: #fff; padding: 30px; border-radius: 20px; width: 90%; max-width: 600px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: translateY(20px); transition: transform 0.3s; border: 2px solid #E0B849; position: relative; }
            .sf-modal-overlay.active .sf-modal-content { transform: translateY(0); }
            
            /* RU: Исправлено центрирование крестика */
            .sf-modal-close { position: absolute; top: 15px; right: 15px; background: transparent; border: none; font-size: 24px; color: #64748b; cursor: pointer; z-index: 10; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; padding: 0; line-height: 1; transition: 0.2s; }
            .sf-modal-close:hover { background: #f1f5f9; color: #082567; }
            
            .sf-modal-title { color: #082567; margin: 0 0 15px 0; font-size: 22px; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
            
            .sf-model-compare-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; }
            .sf-model-compare-table th { background: #f8fafc; padding: 12px; text-align: left; border: 1px solid #e2e8f0; color: #082567; width: 50%; }
            .sf-model-compare-table td { padding: 12px; border: 1px solid #e2e8f0; vertical-align: top; line-height: 1.5; color: #334155; }
            .sf-model-compare-table th.col-a { border-top: 3px solid #0284c7; }
            .sf-model-compare-table th.col-b { border-top: 3px solid #ca8a04; }

            @media (max-width: 768px) {
                .sf-apt-list-header { flex-direction: column; align-items: flex-start; gap: 15px; }
                .sf-apt-card { flex-direction: column; }
                .sf-apt-img { width: 100%; height: 200px; border-right: none; border-bottom: 1px solid #e2e8f0; }
                .sf-card-actions { position: static; display: flex; width: 100%; justify-content: flex-start; margin-top: 15px; padding: 0 20px 20px 20px; box-sizing: border-box; flex-wrap: wrap; }
                .sf-apt-title { padding-right: 0; }
                .sf-apt-info { padding-bottom: 0; }
            }
        </style>

        <div class="sf-apt-list-container">
            
            <div class="sf-apt-list-header">
                <h2>Meine Apartments</h2>
                <a href="<?php echo home_url('/add-apartment/'); ?>" class="sf-3d-btn">+ Neues Apartment</a>
            </div>

            <?php if (empty($apartments)): ?>
                <div style="text-align: center; padding: 60px 20px; background: #f8fafc; border-radius: 12px; border: 2px dashed #cbd5e1;">
                    <span style="font-size: 48px; display: block; margin-bottom: 15px;">🏠</span>
                    <h3 style="color: #082567; margin: 0 0 10px 0; font-size: 22px;">Sie haben noch keine Apartments</h3>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px;">Fügen Sie Ihr erstes Apartment hinzu, um Gastgeber zu werden.</p>
                    <a href="<?php echo home_url('/add-apartment/'); ?>" class="sf-3d-btn">Jetzt starten</a>
                </div>
            <?php else: ?>
                
                <?php foreach ($apartments as $apt): 
                    $thumb_url = get_the_post_thumbnail_url($apt->ID, 'medium') ?: '';
                    $address   = get_post_meta($apt->ID, 'address', true);
                    $price     = get_post_meta($apt->ID, '_sf_selling_price', true);
                    $model     = get_post_meta($apt->ID, '_bsbt_business_model', true) ?: 'model_a';
                    
                    if ($apt->post_status === 'publish') {
                        $status_class = 'sf-status-publish';
                        $status_text  = '🟢 Aktiv';
                    } elseif ($apt->post_status === 'pending') {
                        $status_class = 'sf-status-pending';
                        $status_text  = '🟡 In Prüfung';
                    } else {
                        $status_class = 'sf-status-draft';
                        $status_text  = '🔴 Pausiert / Offline';
                    }

                    $model_class = ($model === 'model_b') ? 'sf-model-b' : 'sf-model-a';
                    $model_text  = ($model === 'model_b') ? 'Modell B' : 'Modell A';

                    $edit_url = add_query_arg('apt_id', $apt->ID, home_url('/edit-apartment/'));
                    $view_url = get_permalink($apt->ID);
                ?>
                    <div class="sf-apt-card">
                        <?php if ($thumb_url): ?>
                            <div class="sf-apt-img" style="background-image: url('<?php echo esc_url($thumb_url); ?>');"></div>
                        <?php else: ?>
                            <div class="sf-apt-img" style="display: flex; align-items: center; justify-content: center; font-size: 24px; color: #cbd5e1;">📷</div>
                        <?php endif; ?>
                        
                        <div class="sf-apt-info">
                            <h3 class="sf-apt-title"><?php echo esc_html($apt->post_title); ?></h3>
                            <p class="sf-apt-meta">
                                <?php if ($address) echo esc_html($address) . ' • '; ?>
                                <?php if ($price) echo '<strong>€' . esc_html($price) . '</strong> / Nacht'; ?>
                            </p>
                            <div>
                                <span class="sf-status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                <span class="sf-model-badge <?php echo $model_class; ?>"><?php echo $model_text; ?></span>
                            </div>
                        </div>

                        <div class="sf-card-actions">
                            <?php if ($apt->post_status === 'publish'): ?>
                                <a href="<?php echo esc_url($view_url); ?>" class="sf-action-btn" target="_blank" title="Auf der Website ansehen">👁️ Ansehen</a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="sf-action-btn">✏️ Bearbeiten</a>
                            
                            <?php if (!$is_b2b): ?>
                                <button type="button" class="sf-action-btn sf-action-model" onclick="sfOpenModelModal(<?php echo $apt->ID; ?>, '<?php echo esc_js($model); ?>')">🔄 Modell wechseln</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
            
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex;">
                <a href="<?php echo home_url('/owner-dashboard/'); ?>" class="sf-3d-btn sf-3d-btn-navy">← Zurück zum Dashboard</a>
            </div>

        </div>

        <div class="sf-modal-overlay" id="sf-model-modal">
            <div class="sf-modal-content">
                <button class="sf-modal-close" onclick="sfCloseModelModal()">×</button>
                <h3 class="sf-modal-title">Geschäftsmodell wechseln</h3>
                
                <table class="sf-model-compare-table">
                    <thead>
                        <tr>
                            <th class="col-a"><?php echo wp_kses_post($mod_a_title); ?></th>
                            <th class="col-b"><?php echo wp_kses_post($mod_b_title); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo wp_kses_post($mod_a_desc); ?></td>
                            <td><?php echo wp_kses_post($mod_b_desc); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="text-align: center; margin-top: 25px;">
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 15px;">
                        <?php echo wp_kses_post($mod_footer); ?>
                    </p>
                    <button type="button" class="sf-3d-btn sf-3d-btn-navy" id="sf-btn-send-model-req" style="width: 100%;">Anfrage senden</button>
                </div>
            </div>
        </div>

        <script>
            let sfCurrentAptId = 0;
            let sfCurrentModel = '';

            function sfOpenModelModal(aptId, model) {
                sfCurrentAptId = aptId;
                sfCurrentModel = model;
                document.getElementById('sf-model-modal').classList.add('active');
            }

            function sfCloseModelModal() {
                document.getElementById('sf-model-modal').classList.remove('active');
                sfCurrentAptId = 0;
            }

            document.getElementById('sf-btn-send-model-req')?.addEventListener('click', function() {
                if (sfCurrentAptId === 0) return;

                const btn = this;
                const originalText = btn.innerText;
                btn.innerText = 'Wird gesendet...';
                btn.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'sf_request_model_change');
                formData.append('apt_id', sfCurrentAptId);
                formData.append('current_model', sfCurrentModel);
                formData.append('_wpnonce', '<?php echo esc_js($nonce); ?>');

                fetch('<?php echo esc_js($ajax_url); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        sfCloseModelModal();
                        alert('Ihre Anfrage wurde erfolgreich an das Stay4Fair Team gesendet. Wir melden uns in Kürze bei Ihnen.');
                    } else {
                        alert('Fehler: ' + (data.data?.message || 'Unbekannter Fehler'));
                    }
                })
                .catch(err => {
                    alert('Ein Systemfehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
            });
        </script>

        <?php
        return ob_get_clean();
    }

    // ==========================================================================
    // AJAX HANDLER: MODEL CHANGE REQUEST / ОБРАБОТЧИК СМЕНЫ МОДЕЛИ
    // ==========================================================================
    public function handleModelChangeRequest(): void
    {
        check_ajax_referer('sf_model_change_nonce');

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['message' => 'Bitte loggen Sie sich ein.']);
        }

        $apt_id = isset($_POST['apt_id']) ? (int)$_POST['apt_id'] : 0;
        $current_model = sanitize_text_field($_POST['current_model'] ?? 'model_a');

        if ($apt_id <= 0) {
            wp_send_json_error(['message' => 'Ungültige Apartment-ID.']);
        }

        // RU: Проверка прав (post_author или bsbt_owner_id)
        $post = get_post($apt_id);
        $bsbt_owner = (int) get_post_meta($apt_id, 'bsbt_owner_id', true);
        if (!$post || ((int)$post->post_author !== $userId && $bsbt_owner !== $userId)) {
            wp_send_json_error(['message' => 'Zugriff verweigert.']);
        }

        $user = get_userdata($userId);
        $owner_name = $user->display_name;
        $apt_title = $post->post_title;
        $target_model = ($current_model === 'model_a') ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)';
        $current_model_label = ($current_model === 'model_a') ? 'Modell A (Direkt)' : 'Modell B (Vermittlung)';

        $edit_url = admin_url("post.php?post={$apt_id}&action=edit");

        $admin_email = get_option('admin_email');
        $subject = "🔄 Modellwechsel-Anfrage: {$apt_title}";

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #1d2327; max-width: 600px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.webp" alt="Stay4Fair" height="40">
            </div>
            <h2 style="color: #082567; border-bottom: 2px solid #E0B849; padding-bottom: 10px;">Anfrage: Geschäftsmodell wechseln</h2>
            <p>Der Vermieter <strong><?php echo esc_html($owner_name); ?></strong> hat einen Wechsel des Geschäftsmodells beantragt.</p>
            
            <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #cbd5e1; background: #f8fafc;"><strong>Apartment:</strong></td>
                    <td style="padding: 10px; border: 1px solid #cbd5e1;"><?php echo esc_html($apt_title); ?> (ID: <?php echo $apt_id; ?>)</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #cbd5e1; background: #f8fafc;"><strong>Aktuelles Modell:</strong></td>
                    <td style="padding: 10px; border: 1px solid #cbd5e1; color: #64748b;"><?php echo $current_model_label; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #cbd5e1; background: #fdf8ed;"><strong>Gewünschtes Modell:</strong></td>
                    <td style="padding: 10px; border: 1px solid #cbd5e1; color: #082567; font-weight: bold;"><?php echo $target_model; ?></td>
                </tr>
            </table>

            <div style="margin-top: 30px; text-align: center;">
                <a href="<?php echo esc_url($edit_url); ?>" style="background: #082567; color: #E0B849; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Wohnung im WP-Admin prüfen</a>
            </div>
        </div>
        <?php
        $message = ob_get_clean();

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($admin_email, $subject, $message, $headers);

        wp_send_json_success(['message' => 'Anfrage versendet.']);
    }
}