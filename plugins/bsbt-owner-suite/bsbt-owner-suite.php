<?php
/**
 * Plugin Name: BSBT – Owner Suite (WhatsApp, Email & Admin UI)
 * Description: Owner communication (WhatsApp + Auto Email) & Admin UI. Decision logic delegated to Owner Portal Core. (V1.7.4 - Email delay fix)
 * Version: 1.7.4
 * Author: BS Business Travelling
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * SAFETY
 * RU: Защита от повторного вызова.
 * EN: Prevent double loading.
 * ======================================================= */
if (defined('BSBT_OWNER_SUITE_LOADED')) return;
define('BSBT_OWNER_SUITE_LOADED', true);

/* =========================================================
 * LOAD ADMIN COLUMNS
 * RU: Загрузка колонок для админки.
 * EN: Load admin columns.
 * ======================================================= */
if ( is_admin() ) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin-columns.php';
}

/* =========================================================
 * HELPERS: MPHB SAFE ACCESS
 * RU: Безопасное извлечение данных из MotoPress (защита от SplObjectStorage).
 * EN: Safe data extraction from MotoPress (SplObjectStorage protection).
 * ======================================================= */
function bsbt_os_get_booking($booking_id) {
    if (!function_exists('MPHB')) return null;
    return MPHB()->getBookingRepository()->findById($booking_id);
}

function bsbt_os_get_room_type_id($booking_id): int {
    $b = bsbt_os_get_booking($booking_id);
    if (!$b) return 0;
    
    if (method_exists($b, 'getReservedRooms')) {
        $rooms = $b->getReservedRooms();
        if (!empty($rooms)) {
            foreach ($rooms as $room) {
                if (is_object($room) && method_exists($room, 'getRoomTypeId')) {
                    return (int)$room->getRoomTypeId();
                }
            }
        }
    }
    return 0;
}

function bsbt_os_get_guests($booking_id): int {
    $b = bsbt_os_get_booking($booking_id);
    if (!$b) return 0;
    
    $g = 0;
    if (method_exists($b, 'getReservedRooms')) {
        $rooms = $b->getReservedRooms();
        if (!empty($rooms)) {
            foreach ($rooms as $room) {
                if (is_object($room)) {
                    if (method_exists($room,'getAdults'))   $g += (int)$room->getAdults();
                    if (method_exists($room,'getChildren')) $g += (int)$room->getChildren();
                    break;
                }
            }
        }
    }
    return $g;
}

/* =========================================================
 * EMAIL LOOKUP (CASCADE)
 * RU: Иерархия поиска Email (Юзер -> Мета -> ACF).
 * EN: Email lookup hierarchy (User -> Meta -> ACF).
 * ======================================================= */
function bsbt_os_get_owner_email($booking_id) {
    $rt = bsbt_os_get_room_type_id($booking_id);
    if (!$rt) return '';

    // 1. User Profile Email
    $owner_id = (int) get_post_meta($rt, 'bsbt_owner_id', true);
    if ($owner_id > 0) {
        $user = get_userdata($owner_id);
        if ($user && is_email($user->user_email)) {
            return $user->user_email;
        }
    }

    // 2. Static Meta Email
    $email = trim((string) get_post_meta($rt, 'owner_email', true));
    if ($email && is_email($email)) return $email;

    // 3. ACF Field Fallback
    $acf = trim((string) get_post_meta($rt, 'field_68fccdd0cdffc', true));
    if ($acf && is_email($acf)) return $acf;

    return ''; 
}

/* =========================================================
 * SNAPSHOT-FIRST PAYOUT LOGIC (READ-ONLY)
 * RU: Логика расчета выплат для превью (до подтверждения брони).
 * EN: Payout calculation logic for preview (before booking confirmation).
 * ======================================================= */
function bsbt_os_calc_payout($booking_id): float {
    $snapshot = get_post_meta($booking_id, '_bsbt_snapshot_owner_payout', true);
    if ($snapshot !== '' && $snapshot !== null) {
        return (float) $snapshot;
    }

    $rt = bsbt_os_get_room_type_id($booking_id);
    $model = get_post_meta($rt, '_bsbt_business_model', true) ?: 'model_a';

    if ($model === 'model_b') {
        $guest_total = 0.0;
        $b = bsbt_os_get_booking($booking_id);
        if ($b && method_exists($b, 'getTotalPrice')) {
            $guest_total = (float) $b->getTotalPrice();
        }
        if ($guest_total <= 0) $guest_total = (float) get_post_meta($booking_id, '_mphb_total_price', true);
        if ($guest_total <= 0) $guest_total = (float) get_post_meta($booking_id, 'mphb_booking_total_price', true);

        $fee_rate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15;
        $fee = $guest_total * $fee_rate;
        return round($guest_total - $fee, 2);
    } else {
        if (!$rt) return 0.0;
        $ppn = (float) get_post_meta($rt, 'owner_price_per_night', true);
        if (!$ppn && function_exists('get_field')) $ppn = (float) get_field('owner_price_per_night', $rt);
        if ($ppn <= 0) return 0.0;

        $in  = (string) get_post_meta($booking_id, 'mphb_check_in_date', true);
        $out = (string) get_post_meta($booking_id, 'mphb_check_out_date', true);
        if (!$in || !$out) return 0.0;

        $nights = max(0, (strtotime($out) - strtotime($in)) / 86400);
        return round($ppn * $nights, 2);
    }
}

/* =========================================================
 * WHATSAPP MESSAGE
 * RU: Генерация текста для WhatsApp.
 * EN: WhatsApp text generation.
 * ======================================================= */
function bsbt_os_build_whatsapp_text($booking_id): string {
    $rt = bsbt_os_get_room_type_id($booking_id);
    $owner = (string)get_post_meta($rt,'owner_name',true) ?: 'Guten Tag';
    $apt   = $rt ? (get_the_title($rt) ?: '—') : '—';
    $in    = (string)get_post_meta($booking_id,'mphb_check_in_date',true);
    $out   = (string)get_post_meta($booking_id,'mphb_check_out_date',true);
    $g     = bsbt_os_get_guests($booking_id);
    $pay   = number_format(bsbt_os_calc_payout($booking_id),2,',','.');
    $portal= 'https://stay4fair.com/owner-bookings/?booking_id='.$booking_id;

    return "Hallo {$owner} | neue Buchungsanfrage | Wohnung: {$apt} | Zeitraum: {$in} – {$out} | Gäste: {$g} | Auszahlung für Sie: {$pay} € | Bitte loggen Sie sich in Ihr Eigentümer-Konto ein und bestätigen oder lehnen Sie dort ab: {$portal} | Vielen Dank | Stay4Fair.com";
}

function bsbt_os_whatsapp_url($booking_id): string {
    $rt = bsbt_os_get_room_type_id($booking_id);
    $phone = preg_replace('/\D+/','',(string)get_post_meta($rt,'owner_phone',true));
    if (!$phone) return '';
    if (strpos($phone,'0')===0) $phone = '49'.substr($phone,1);
    return 'https://wa.me/'.$phone.'?text='.rawurlencode(bsbt_os_build_whatsapp_text($booking_id));
}

/* =========================================================
 * AUTO EMAIL REQUEST LOGIC
 * RU: Автоматическая отправка Email владельцу.
 * EN: Auto Email request logic to owner.
 * ======================================================= */
function bsbt_os_send_request_email($booking_id, $manual = false) {
    $booking_id = (int) $booking_id;
    if ($booking_id <= 0) return false;

    // Prevent duplicate sending / Защита от дублей
    if (!$manual && get_post_meta($booking_id, '_bsbt_req_email_sent', true) === '1') {
        return false;
    }

    $to = bsbt_os_get_owner_email($booking_id);
    if (empty($to)) {
        update_post_meta($booking_id, '_bsbt_req_email_err', 'E-Mail not sent (No Email found).');
        return false;
    }

    $rt = bsbt_os_get_room_type_id($booking_id);
    $owner = (string)get_post_meta($rt,'owner_name',true) ?: 'Guten Tag';
    $apt   = $rt ? (get_the_title($rt) ?: '—') : '—';
    $in    = (string)get_post_meta($booking_id,'mphb_check_in_date',true);
    $out   = (string)get_post_meta($booking_id,'mphb_check_out_date',true);
    $g     = bsbt_os_get_guests($booking_id);
    $pay   = number_format(bsbt_os_calc_payout($booking_id),2,',','.');
    $link  = 'https://stay4fair.com/owner-bookings/?booking_id='.$booking_id;

    $subject = "Neue Buchungsanfrage – Bitte bestätigen (#{$booking_id})";

    // ✅ ИСПОЛЬЗУЕМ ПРОВЕРЕННЫЙ PNG ИЗ ВАУЧЕРА / USE VERIFIED PNG FROM VOUCHER
    $logo = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Neue Buchungsanfrage</title>
    </head>
    <body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif;">
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 20px 0;">
            <tr>
                <td align="center">
                    <table width="100%" max-width="600" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        
                        <tr>
                            <td style="padding: 30px; background-color: #ffffff; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                <img src="<?php echo esc_url($logo); ?>" alt="Stay4Fair.com" width="220" border="0" style="display: block; margin: 0 auto; outline: none; text-decoration: none;">
                            </td>
                        </tr>

                        <tr>
                            <td style="padding: 40px 30px; color: #1d2327;">
                                <h1 style="color: #082567; margin: 0 0 10px 0; font-size: 24px; font-weight: bold; text-align: center;">Neue Buchungsanfrage</h1>
                                <p style="color: #64748b; margin: 0 0 30px 0; font-size: 14px; text-align: center;">Buchung #<?php echo $booking_id; ?></p>
                                
                                <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6;">Hallo <?php echo esc_html($owner); ?>,</p>
                                <p style="margin: 0 0 30px 0; font-size: 16px; line-height: 1.6;">es liegt eine neue Buchungsanfrage für Ihr Apartment vor. Bitte loggen Sie sich ein, um die Details zu prüfen und die Entscheidung zu treffen.</p>
                                
                                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
                                    <tr><td style="padding: 8px 0; color: #64748b; font-size: 14px;">Wohnung:</td><td style="padding: 8px 0; font-size: 14px; color: #082567; text-align:right;"><strong><?php echo esc_html($apt); ?></strong></td></tr>
                                    <tr style="border-top: 1px solid #e2e8f0;"><td style="padding: 8px 0; color: #64748b; font-size: 14px;">Zeitraum:</td><td style="padding: 8px 0; font-size: 14px; color: #082567; text-align:right;"><strong><?php echo esc_html($in); ?> – <?php echo esc_html($out); ?></strong></td></tr>
                                    <tr style="border-top: 1px solid #e2e8f0;"><td style="padding: 8px 0; color: #64748b; font-size: 14px;">Gäste:</td><td style="padding: 8px 0; font-size: 14px; color: #082567; text-align:right;"><strong><?php echo esc_html($g); ?></strong></td></tr>
                                    <tr style="border-top: 2px solid #cbd5e1;"><td style="padding: 15px 0 0 0; color: #082567; font-size: 18px; font-weight: bold;">Ihre Auszahlung:</td><td style="padding: 15px 0 0 0; font-size: 18px; font-weight: bold; color: #082567; text-align:right;"><strong><?php echo $pay; ?> €</strong></td></tr>
                                </table>

                                <p style="text-align: center; margin: 40px 0 0 0;">
                                    <a href="<?php echo esc_url($link); ?>" style="display: inline-block; background-color: #082567; color: #E0B849; text-decoration: none; padding: 16px 32px; border-radius: 10px; font-weight: bold; font-size: 16px; box-shadow: 0 6px 0 #03143c, 0 8px 15px rgba(0,0,0,0.3);">Aktion bestätigen / ablehnen</a>
                                </p>

                                <div style="font-size: 12px; color: #64748b; text-align: center; margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                                    Falls der Button nicht funktioniert, kopieren Sie diesen Link:<br>
                                    <a href="<?php echo esc_url($link); ?>" style="color: #082567; word-break: break-all;"><?php echo esc_url($link); ?></a>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding: 30px; background-color: #082567; color: #ffffff; border-radius: 0 0 16px 16px; text-align: center;">
                                <p style="margin: 0 0 15px 0; font-size: 14px; font-weight: bold;">Haben Sie Fragen?</p>
                                <p style="margin: 0 0 8px 0; font-size: 13px;">WhatsApp / Tel: <a href="tel:+4917624615269" style="color: #E0B849; text-decoration: none;">+49 176 24615269</a></p>
                                <p style="margin: 0; font-size: 13px;">E-Mail: <a href="mailto:business@stay4fair.com" style="color: #E0B849; text-decoration: none;">business@stay4fair.com</a></p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    $message = ob_get_clean();

    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $sent = wp_mail($to, $subject, $message, $headers);

    if ($sent) {
        update_post_meta($booking_id, '_bsbt_req_email_sent', '1');
        delete_post_meta($booking_id, '_bsbt_req_email_err');
        return true;
    } else {
        update_post_meta($booking_id, '_bsbt_req_email_err', 'E-Mail req failed (Server).');
        return false;
    }
}

// ✅ ИЗМЕНЕНИЕ: Хук отправки письма только при статусе "Pending Admin" (После успешной оплаты/холда)
add_action('transition_post_status', function($new_status, $old_status, $post) {
    if ($post->post_type !== 'mphb_booking') return;
    
    // RU: Ждем СТРОГО статуса 'pending' (Awaiting Admin Confirmation). 
    // Мы убрали 'pending-payment' и 'pending-user', чтобы не отправлять до оплаты в Woo!
    $is_pending_admin  = in_array( (string)$new_status, ['pending', 'mphb-pending'], true );
    $was_pending_admin = in_array( (string)$old_status, ['pending', 'mphb-pending'], true );
    
    if ($is_pending_admin && !$was_pending_admin) {
        bsbt_os_send_request_email($post->ID, false);
    }
}, 20, 3);

// AJAX Handler for manual resend
add_action('wp_ajax_bsbt_send_req_email', function() {
    check_ajax_referer('bsbt_owner_action', '_wpnonce');
    $bid = (int)$_POST['booking_id'];
    bsbt_os_send_request_email($bid, true);
    wp_send_json_success();
});

/* =========================================================
 * ADMIN METABOX (UI ONLY)
 * RU: Рендер метабокса в админке брони.
 * EN: Render metabox in booking admin.
 * ======================================================= */
add_action('add_meta_boxes', function(){
    if (!current_user_can('manage_options')) return;
    add_meta_box(
        'bsbt_owner_suite_box',
        'BSBT – Owner Actions',
        'bsbt_os_render_box',
        'mphb_booking',
        'side',
        'high'
    );
});

function bsbt_os_render_box($post){

    if (!class_exists('BSBT_Owner_Decision_Core')) {
        echo "<div style='color:#c62828;font-weight:600'>Owner Portal Core not loaded.</div>";
        return;
    }

    $bid = (int)$post->ID;

    $decision = (string)get_post_meta($bid,'_bsbt_owner_decision',true);
    $source   = (string)get_post_meta($bid,'_bsbt_owner_decision_source',true);
    $time     = (string)get_post_meta($bid,'_bsbt_owner_decision_time',true);
    $user_id  = (int)get_post_meta($bid,'_bsbt_owner_decision_user_id',true);

    $rt = bsbt_os_get_room_type_id($bid);
    $owner_id = (int)get_post_meta($rt,'bsbt_owner_id',true);
    $wa = bsbt_os_whatsapp_url($bid);
    $text = bsbt_os_build_whatsapp_text($bid);
    $pay = number_format(bsbt_os_calc_payout($bid),2,',','.');
    $nonce = wp_create_nonce('bsbt_owner_action');
    $ajax = admin_url('admin-ajax.php');

    // Email Status
    $email_sent = get_post_meta($bid, '_bsbt_req_email_sent', true) === '1';
    $email_err  = get_post_meta($bid, '_bsbt_req_email_err', true);

    $status = 'OFFEN'; 
    $color='#f9a825';
    if ($decision==='approved'){ $status='BESTÄTIGT'; $color='#2e7d32'; }
    if ($decision==='declined'){ $status='ABGELEHNT'; $color='#c62828'; }

    echo "<div style='font-size:12px;line-height:1.45'>";

    echo "<p><strong>Status:</strong> <span style='color:$color;font-weight:700'>$status</span></p>";
    echo "<p><strong>Owner:</strong> ".($owner_id?'🟢 registriert':'🔴 nicht registriert')."</p>";
    
    echo "<p style='margin:0 0 8px 0;'><strong>Request E-Mail:</strong> ";
    if ($email_sent) {
        echo "<span style='color:#2e7d32;'>Gesendet</span>";
    } elseif ($email_err) {
        echo "<span style='color:#c62828; font-size:11px;' title='".esc_attr($email_err)."'>Error</span>";
    } else {
        echo "<span style='color:#666;'>Noch nicht gesendet</span>";
    }
    echo "</p>";

    echo "<p><strong>Auszahlung:</strong> {$pay} €</p>";

    if ($decision){
        echo "<hr style='margin:8px 0'>";
        echo "<p><strong>Decision source:</strong> ".esc_html($source ?: '—')."</p>";
        if ($user_id){
            $u = get_userdata($user_id);
            if ($u) echo "<p><strong>Admin:</strong> ".esc_html($u->display_name)."</p>";
        }
        if ($time) echo "<p><strong>Zeitpunkt:</strong> ".esc_html($time)."</p>";
    }

    echo "<hr style='margin:8px 0'>";

    echo "<label><strong>WhatsApp Text</strong></label>";
    echo "<textarea id='bsbt-wa-text' style='width:100%;min-height:120px;font-size:11px'>".esc_textarea($text)."</textarea>";

    echo "<p style='display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;'>";
    if ($wa) echo "<a class='button button-primary' target='_blank' href='".esc_url($wa)."'>WhatsApp</a>";
    echo "<button type='button' class='button bsbt-email-req' data-id='$bid' data-nonce='$nonce'>E-Mail Senden</button>";
    echo "<button type='button' class='button' onclick=\"var t=document.getElementById('bsbt-wa-text');t.select();document.execCommand('copy');\">Copy</button>";
    echo "</p>";

    if (!$decision){
        echo "<hr style='margin:12px 0 8px 0'>";
        echo "<p style='font-size:11px;color:#666;margin:0 0 8px 0'><em>Nur verwenden, wenn der Eigentümer außerhalb des Portals bestätigt hat.</em></p>";
        echo "<p style='display:flex;gap:6px'>";
        echo "<button class='button button-primary bsbt-confirm' data-id='$bid' data-nonce='$nonce'>Bestätigen</button>";
        echo "<button class='button bsbt-reject' data-id='$bid' data-nonce='$nonce'>Ablehnen</button>";
        echo "</p>";
    } else {
        echo "<div style='margin-top:8px;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;font-size:11px'>
            Aktion gesperrt – Entscheidung bereits getroffen.
        </div>";
    }

    echo "</div>";
    ?>

    <script>
    (function(){
        const ajax = <?php echo json_encode($ajax); ?>;
        
        document.addEventListener('click',function(e){
            
            const c=e.target.closest('.bsbt-confirm');
            const r=e.target.closest('.bsbt-reject');
            if(c||r){
                if(!confirm('Aktion bestätigen?')) return;
                const b=c||r;
                const d=new URLSearchParams();
                d.append('action',c?'bsbt_confirm_booking':'bsbt_reject_booking');
                d.append('booking_id',b.dataset.id);
                d.append('_wpnonce',b.dataset.nonce);
                fetch(ajax,{
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:d
                }).then(()=>location.reload());
                return;
            }

            const m = e.target.closest('.bsbt-email-req');
            if(m){
                m.innerText = 'Sende...';
                m.disabled = true;
                const d=new URLSearchParams();
                d.append('action', 'bsbt_send_req_email');
                d.append('booking_id', m.dataset.id);
                d.append('_wpnonce', m.dataset.nonce);
                fetch(ajax,{
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:d
                }).then(()=>location.reload());
            }

        });
    })();
    </script>
    <?php
}