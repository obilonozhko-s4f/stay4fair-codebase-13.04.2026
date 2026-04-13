<?php
declare(strict_types=1);

/**
 * Plugin Name: BSBT – Owner Bookings (V8.1.0 – iCal Sync Detection Added)
 * RU: Интегрировано автоматическое распознавание iCal бронирований (Airbnb, Booking).
 * EN: Integrated automatic detection of iCal bookings (Airbnb, Booking).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ✅ Core is required (otherwise "Core not loaded")
require_once plugin_dir_path(__FILE__) . 'includes/owner-decision-core.php';

final class BSBT_Owner_Bookings {

    public function __construct() {
        remove_shortcode('bsbt_owner_bookings');
        add_shortcode('bsbt_owner_bookings', [$this, 'render']);

        add_action('wp_ajax_bsbt_confirm_booking', [$this, 'ajax_confirm']);
        add_action('wp_ajax_bsbt_reject_booking',  [$this, 'ajax_reject']);
        
        // RU: Хук для запроса на отмену
        // EN: Hook for cancellation request
        add_action('wp_ajax_bsbt_request_cancel_booking', [$this, 'ajax_request_cancel']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* =========================
     * ASSETS
     * ========================= */
    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;
        if ( ! $this->is_owner_or_admin() ) return;

        wp_enqueue_style(
            'bsbt-owner-bookings',
            plugin_dir_url(__FILE__) . 'assets/css/owner-bookings.css',
            [],
            '8.1.0'
        );
    }

    /* =========================
     * HELPERS
     * ========================= */
    private function is_owner_or_admin(): bool {
        if ( current_user_can('manage_options') ) return true;
        $u = wp_get_current_user();
        return in_array('owner', (array)$u->roles, true);
    }

    private function get_booking_owner_id(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid) return $oid;

        if (!function_exists('MPHB')) return 0;
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return 0;

        $room = $b->getReservedRooms()[0] ?? null;
        if (!$room || !method_exists($room,'getRoomTypeId')) return 0;

        return (int) get_post_meta($room->getRoomTypeId(), 'bsbt_owner_id', true);
    }

    private function get_booking_data(int $booking_id): array {
        $apt_id = 0; $apt_title = '—'; $guests = 0;

        if (function_exists('MPHB')) {
            $b = MPHB()->getBookingRepository()->findById($booking_id);
            if ($b) {
                $room = $b->getReservedRooms()[0] ?? null;
                if ($room && method_exists($room,'getRoomTypeId')) {
                    $apt_id = (int)$room->getRoomTypeId();
                    $apt_title = get_the_title($apt_id) ?: '—';
                }
                if ($room && method_exists($room,'getAdults'))   $guests += (int)$room->getAdults();
                if ($room && method_exists($room,'getChildren')) $guests += (int)$room->getChildren();
            }
        }

        return [$apt_id, $apt_title, $guests];
    }

    private function get_dates(int $booking_id): array {
        return [
            get_post_meta($booking_id,'mphb_check_in_date',true),
            get_post_meta($booking_id,'mphb_check_out_date',true)
        ];
    }

    private function nights(string $in, string $out): int {
        if (!$in || !$out) return 0;
        return max(0,(strtotime($out)-strtotime($in))/86400);
    }

    /* =========================================================
       MODEL DETECTION (Snapshot first)
       ========================================================= */
    private function get_booking_model(int $booking_id): string {
        $m = (string) get_post_meta($booking_id, '_bsbt_snapshot_model', true);
        $m = trim($m);
        if ($m !== '') return $m;

        if (function_exists('MPHB')) {
            try {
                $b = MPHB()->getBookingRepository()->findById($booking_id);
                if ($b) {
                    $room = $b->getReservedRooms()[0] ?? null;
                    if ($room && method_exists($room,'getRoomTypeId')) {
                        $rt = (int) $room->getRoomTypeId();
                        if ($rt > 0) {
                            $rm = (string) get_post_meta($rt, '_bsbt_business_model', true);
                            $rm = trim($rm);
                            if ($rm !== '') return $rm;
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // ignore
            }
        }
        return 'model_a';
    }

    /* =========================================================
       WOO ORDER RESOLVER (Safe, no dependency on Core private)
       ========================================================= */
    private function find_order_for_booking(int $booking_id): ?WC_Order {
        if ($booking_id <= 0) return null;
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) return null;

        $statuses = array_keys( wc_get_order_statuses() );

        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => '_bsbt_booking_id',
            'meta_value' => $booking_id,
            'status'     => $statuses,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);
        if (!empty($orders) && $orders[0] instanceof WC_Order) return $orders[0];

        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => '_mphb_booking_id',
            'meta_value' => $booking_id,
            'status'     => $statuses,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);
        if (!empty($orders) && $orders[0] instanceof WC_Order) return $orders[0];

        $order_id = $this->resolve_order_id_via_mphb_payment_bridge($booking_id);
        if ($order_id > 0) {
            $o = wc_get_order($order_id);
            if ($o instanceof WC_Order) return $o;
        }

        return null;
    }

    private function resolve_order_id_via_mphb_payment_bridge(int $booking_id): int {
        if ($booking_id <= 0) return 0;
        if (!function_exists('get_posts')) return 0;

        global $wpdb;

        $payments = get_posts([
            'post_type'      => 'mphb_payment',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_mphb_booking_id',
                    'value'   => (string) $booking_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $payment_id = !empty($payments) ? (int)$payments[0] : 0;

        if ($payment_id <= 0) {
            $payments = get_posts([
                'post_type'      => 'mphb_payment',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'meta_query'     => [
                    [
                        'key'     => 'mphb_booking_id',
                        'value'   => (string) $booking_id,
                        'compare' => '=',
                    ],
                ],
            ]);
            $payment_id = !empty($payments) ? (int)$payments[0] : 0;
        }

        if ($payment_id <= 0) return 0;

        $table_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $table_items    = $wpdb->prefix . 'woocommerce_order_items';

        $exists_itemmeta = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_itemmeta) );
        $exists_items    = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_items) );

        if ($exists_itemmeta !== $table_itemmeta || $exists_items !== $table_items) return 0;

        $sql = "
            SELECT oi.order_id
            FROM {$table_itemmeta} oim
            JOIN {$table_items} oi ON oi.order_item_id = oim.order_item_id
            WHERE oim.meta_key = %s
              AND oim.meta_value = %s
            ORDER BY oi.order_id DESC
            LIMIT 1
        ";

        $order_id = (int) $wpdb->get_var(
            $wpdb->prepare($sql, '_mphb_payment_id', (string)$payment_id)
        );

        return $order_id > 0 ? $order_id : 0;
    }

    /* =========================================================
       🔒 PAYOUT (Model A/B aware)
       ========================================================= */
    private function payout(int $booking_id, int $nights): ?float {
        $snapshot_payout = get_post_meta($booking_id, '_bsbt_snapshot_owner_payout', true);
        if ($snapshot_payout !== '') {
            return (float) $snapshot_payout;
        }

        $model = $this->get_booking_model($booking_id);

        if ($model === 'model_b') {
            $order = $this->find_order_for_booking($booking_id);
            if ($order instanceof WC_Order) {
                $guest_total = round(max(0.0, (float)$order->get_total()), 2);
                if ($guest_total <= 0) return null;

                $fee_brut = round($guest_total * 0.15, 2); 
                return round($guest_total - $fee_brut, 2);
            }
            return null;
        }

        if ($nights <= 0) return null;

        $ppn = get_post_meta($booking_id, 'bsbt_owner_price_per_night', true);
        if ($ppn === '') $ppn = get_post_meta($booking_id, 'owner_price_per_night', true);
        $ppn = (float) $ppn;

        if ($ppn > 0) return round($ppn * $nights, 2);

        if (!function_exists('MPHB')) return null;

        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return null;

        $room = $b->getReservedRooms()[0] ?? null;
        if (!$room || !method_exists($room,'getRoomTypeId')) return null;

        $room_type_id = (int)$room->getRoomTypeId();
        $ppn_rt = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);

        return $ppn_rt > 0 ? round($ppn_rt * $nights, 2) : null;
    }

    /* =========================
     * RENDER
     * ========================= */
    public function render() {
        if ( ! is_user_logged_in() || ! $this->is_owner_or_admin() ) return 'Zugriff verweigert.';

        $user_id  = get_current_user_id();
        $is_admin = current_user_can('manage_options');

        $ajax  = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('bsbt_owner_action');

        $countries = class_exists('WC_Countries') ? new WC_Countries() : null;

        $per_page = 25;
        $paged    = max(1, (int)($_GET['paged'] ?? 1));

        $q = new WP_Query([
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ]);

        ob_start(); ?>

        <div class="bsbt-container">
            <div class="bsbt-card">
                <table class="bsbt-table">
                    <thead>
                        <tr>
                            <th>ID / Apt</th>
                            <th>Gast & Kontakt</th>
                            <th>Aufenthalt</th>
                            <th>Status</th>
                            <th>Auszahlung</th>
                            <th style="text-align:center;">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php while($q->have_posts()): $q->the_post();
                        $bid = get_the_ID();
                        if(!$is_admin && $this->get_booking_owner_id($bid) !== $user_id) continue;

                        $owner_decision = get_post_meta($bid,'_bsbt_owner_decision',true);
                        $confirmed = ($owner_decision === 'approved');
                        $declined = ($owner_decision === 'declined');
                        $expired  = ($owner_decision === 'expired');
                        $cancelled = ($owner_decision === 'cancelled');

                        // RU: Логика обнаружения iCal бронирований
                        // EN: iCal booking detection logic
                        $is_imported = false;
                        $platform_name = '';
                        
                        if (function_exists('MPHB')) {
                            $b_obj = MPHB()->getBookingRepository()->findById($bid);
                            if ($b_obj && $b_obj->isImported()) {
                                $is_imported = true;
                                $prodid = strtolower((string) $b_obj->getICalProdid());
                                $summary = strtolower((string) $b_obj->getICalSummary());
                                if (strpos($prodid, 'airbnb') !== false || strpos($summary, 'airbnb') !== false) {
                                    $platform_name = 'Airbnb';
                                } elseif (strpos($prodid, 'booking') !== false || strpos($summary, 'booking') !== false) {
                                    $platform_name = 'Booking.com';
                                } else {
                                    $platform_name = 'iCal';
                                }

                                // Переопределяем статусы: внешняя бронь автоматически подтверждена
                                if ($b_obj->getStatus() === 'cancelled') {
                                    $cancelled = true;
                                    $confirmed = false;
                                } else {
                                    $confirmed = true;
                                    $declined = false;
                                    $expired = false;
                                }
                            }
                        }

                        $created_raw = get_post_field('post_date', $bid);
                        $created_formatted = $created_raw
                            ? date_i18n('d.m.Y H:i', strtotime($created_raw))
                            : '—';

                        [$apt_id,$apt_title,$guests_count] = $this->get_booking_data($bid);
                        [$in,$out] = $this->get_dates($bid);
                        $nights = $this->nights($in,$out);
                        
                        $payout = $this->payout($bid,$nights);

                        if ($cancelled && !$is_imported) {
                            $snapshot = get_post_meta($bid, '_bsbt_financial_snapshot', true);
                            $refund_type = is_array($snapshot) ? ($snapshot['refund_type'] ?? '') : '';
                            if ($refund_type === '100%') {
                                $payout = 0.0;
                            }
                        }

                        // RU: Отображение суммы для iCal
                        // EN: Payout display for iCal
                        $payout_display = '';
                        if ($is_imported) {
                             $payout_display = '<span style="font-size:11px; color:#64748b;">Abrechnung via<br><strong style="color:#082567;">' . esc_html($platform_name) . '</strong></span>';
                        } else {
                             $payout_display = '<span class="t-bold">' . ($payout !== null ? number_format((float)$payout,2,',','.') . ' €' : '— €') . '</span>';
                        }

                        $checkin_time = get_post_meta($bid,'mphb_checkin_time',true);

                        $guest_first = trim((string) get_post_meta($bid,'mphb_first_name',true));
                        $guest_last  = trim((string) get_post_meta($bid,'mphb_last_name',true));
                        $guest = trim($guest_first . ' ' . $guest_last) ?: 'Gast';
                        
                        if ($is_imported && $guest === 'Gast') {
                            $guest = $platform_name . ' Gast';
                        }

                        $country_code = get_post_meta($bid,'mphb_country',true);
                        $country = $country_code ?: '—';
                        if ($country_code && $countries instanceof WC_Countries) {
                            $list = $countries->get_countries();
                            $country = $list[$country_code] ?? $country_code;
                        }

                        $company = get_post_meta($bid,'mphb_company',true);
                        $addr1   = get_post_meta($bid,'mphb_address1',true);
                        $zip     = get_post_meta($bid,'mphb_zip',true);
                        $city    = get_post_meta($bid,'mphb_city',true);

                        $email = (string)get_post_meta($bid,'mphb_email',true);
                        $phone = (string)get_post_meta($bid,'mphb_phone',true);
                    ?>

                        <tr>
                            <td>
                                <span class="t-bold">Booking ID: #<?= (int)$bid ?></span>
                                <span class="t-gray">Wohnungs ID: <?= (int)$apt_id ?></span>
                                <span class="apt-name-static"><?= esc_html($apt_title) ?></span>
                            </td>

                            <td>
                                <?php if(!$confirmed && !$declined && !$expired && !$cancelled && !$is_imported): ?>
                                    <span class="badge-new">NEUE ANFRAGE</span>
                                <?php elseif($is_imported): ?>
                                    <span class="badge-new" style="background:#082567; color:#E0B849;">⚙️ <?= strtoupper($platform_name) ?></span>
                                <?php endif; ?>

                                <div class="t-gray" style="margin-top:4px; font-size:12px;">
                                    Erstellt am: <strong><?= esc_html($created_formatted) ?></strong>
                                </div>

                                <?php if($expired): ?>
                                    <div style="margin-top:6px; font-size:12px; color:#d32f2f; font-weight:600;">
                                        Automatisch storniert (keine Rückmeldung innerhalb von 24h)
                                    </div>
                                <?php endif; ?>

                                <span class="t-bold"><?= esc_html($guest) ?></span>
                                <span class="t-gray"><?= esc_html($country) ?> · <?= (int)$guests_count ?> Gäste</span>

                                <?php if($confirmed && ($company || $addr1 || $zip || $city)): ?>
                                    <div class="t-gray" style="margin-top:6px;">
                                        <?php if($company): ?><strong><?= esc_html($company) ?></strong><br><?php endif; ?>
                                        <?= esc_html(trim((string)$addr1)) ?><br>
                                        <?= esc_html(trim((string)$zip.' '.(string)$city)) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($confirmed && !$is_imported): ?>
                                    <div class="contact-box" style="margin-top:10px; border-top:1px solid #eee; padding-top:8px;">
                                        <div style="margin-bottom:8px;">
                                            <span style="display:block; font-size:11px; color:#999; text-transform:uppercase;">E-Mail & Telefon:</span>
                                            <strong style="font-size:13px; color:#333; display:block;"><?= esc_html($email) ?></strong>
                                            <strong style="font-size:13px; color:#333; display:block;"><?= esc_html($phone) ?></strong>
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <a href="https://wa.me/<?= esc_attr(preg_replace('/\D+/','',$phone)) ?>" target="_blank" class="button" style="background:#25D366; color:#fff; border:none; padding:4px 10px; font-size:12px; border-radius:4px; text-decoration:none;">WhatsApp</a>
                                            <a href="tel:<?= esc_attr($phone) ?>" class="button" style="background:#007bff; color:#fff; border:none; padding:4px 10px; font-size:12px; border-radius:4px; text-decoration:none;">Call</a>
                                        </div>
                                    </div>
                                <?php elseif($declined): ?>
                                    <div class="locked-info" style="color:#d32f2f; margin-top:10px;">Anfrage abgelehnt</div>
                                <?php elseif($cancelled): ?>
                                    <div class="locked-info" style="color:#d32f2f; margin-top:10px;">Buchung storniert</div>
                                <?php elseif(!$expired && !$is_imported): ?>
                                    <div class="locked-info" style="margin-top:10px;">Kontaktdaten werden nach Bestätigung freigeschaltet</div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="t-bold"><?= esc_html((string)$in) ?> – <?= esc_html((string)$out) ?></span>
                                <span class="t-gray"><?= (int)$nights ?> Nächte</span>
                            </td>

                            <td>
                                <span style="color:<?= $confirmed?'#25D366':(($declined||$expired||$cancelled)?'#d32f2f':'#d32f2f') ?>;font-weight:900;">
                                    <?php
                                        if($is_imported && !$cancelled) echo 'BESTÄTIGT (' . strtoupper($platform_name) . ')';
                                        elseif($confirmed) echo 'BESTÄTIGT';
                                        elseif($declined) echo 'ABGELEHNT';
                                        elseif($expired) echo 'EXPIRED';
                                        elseif($cancelled) echo 'STORNIERT';
                                        else echo 'OFFEN';
                                    ?>
                                </span>
                            </td>

                            <td>
                                <?= $payout_display ?>
                            </td>

                            <td style="text-align:center;">
                                <?php if ($cancelled): ?>
                                    <div style="color:#d32f2f;font-weight:600;line-height:1.4; text-align: left; padding: 5px;">
                                        ⚠️ Storniert.<br>
                                        <span style="font-weight:normal; font-size:12px;">Der Gast hat diese Buchung storniert. Ihr Kalender ist wieder frei.</span>
                                    </div>
                                <?php elseif ($is_imported): ?>
                                    <div style="color:#082567;font-weight:600;line-height:1.4; text-align: left; padding: 5px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        🌐 Externe Buchung<br>
                                        <span style="font-weight:normal; font-size: 11px;">Automatisch über <?= esc_html($platform_name) ?> synchronisiert. Keine Aktion erforderlich.</span>
                                    </div>
                                <?php elseif ($owner_decision === 'approved'): ?>
                                    <div style="color:#25D366;font-weight:600;line-height:1.4; text-align: left; padding: 5px;">
                                        ✔ Bestätigung erhalten.<br>
                                        <span style="font-weight:normal;">Wir übernehmen nun die weitere Organisation.<br>
                                        Bitte bereiten Sie die Wohnung vor und organisieren Sie die Schlüsselübergabe.</span>
                                        <?php if($checkin_time): ?><br><strong>Ankunftszeit: <?= esc_html((string)$checkin_time) ?></strong><?php endif; ?>
                                    </div>

                                    <?php
                                    $cancel_req = get_post_meta($bid, '_bsbt_cancel_requested', true);
                                    if ($cancel_req): ?>
                                        <div style="margin-top:10px; font-size:11px; color:#64748b; font-weight:600;">
                                            ⏳ Stornierungsanfrage gesendet
                                        </div>
                                    <?php else: ?>
                                        <button class="button btn-action-request-cancel bsbt-btn-base"
                                                data-id="<?= (int)$bid ?>"
                                                data-nonce="<?= esc_attr($nonce) ?>"
                                                style="background: transparent; color: #64748b; border: 1px solid #cbd5e1; margin-top: 10px; font-size: 11px; padding: 4px 8px; border-radius: 4px; cursor: pointer; transition: 0.3s;">
                                            Stornierung anfragen
                                        </button>
                                    <?php endif; ?>

                                <?php elseif ($owner_decision === 'declined'): ?>
                                    <div style="color:#d32f2f;font-weight:600;line-height:1.4; text-align: left; padding: 5px;">
                                        🚫 Abgelehnt.<br>
                                        <span style="font-weight:normal;">Diese Buchung wird innerhalb von 7 Tagen aus Ihrer Liste gelöscht.</span>
                                    </div>

                                <?php elseif ($owner_decision === 'expired'): ?>
                                    <div style="color:#d32f2f;font-weight:600;line-height:1.4; text-align: left; padding: 5px;">
                                        ⏳ Automatisch storniert (keine Rückmeldung).<br>
                                        <span style="font-weight:normal;">Bitte prüfen Sie Ihre Verfügbarkeit.</span>
                                    </div>

                                <?php else: ?>
                                    <button class="button btn-action-confirm bsbt-btn-base"
                                            data-id="<?= (int)$bid ?>"
                                            data-nonce="<?= esc_attr($nonce) ?>">Bestätigen</button>
                                    <button class="button btn-action-reject bsbt-btn-base"
                                            data-id="<?= (int)$bid ?>"
                                            data-nonce="<?= esc_attr($nonce) ?>"
                                            style="margin-top:5px;">Ablehnen</button>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endwhile; wp_reset_postdata(); ?>

                    </tbody>
                </table>

                <?php
                if ( $q->max_num_pages > 1 ) {
                    $base_url = remove_query_arg('paged');
                    $base_url = add_query_arg('paged', '%#%', $base_url);

                    echo '<div style="padding:14px 16px 18px; text-align:right;">';
                    echo paginate_links([
                        'base'      => $base_url,
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => (int) $q->max_num_pages,
                        'type'      => 'plain',
                        'prev_text' => '←',
                        'next_text' => '→',
                    ]);
                    echo '</div>';
                }
                ?>

            </div>
        </div>

        <script>
        (function(){
            const ajax = <?= json_encode($ajax) ?>;
            
            document.querySelectorAll('.btn-action-confirm,.btn-action-reject').forEach(btn=>{
                btn.addEventListener('click',()=>{
                    if(!confirm('Aktion bestätigen?')) return;
                    const d=new URLSearchParams();
                    d.append('action',btn.classList.contains('btn-action-confirm')?'bsbt_confirm_booking':'bsbt_reject_booking');
                    d.append('booking_id',btn.dataset.id);
                    d.append('_wpnonce',btn.dataset.nonce);
                    fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d})
                        .then(r=>r.json())
                        .then(res=>{
                            if(res && res.success){ location.reload(); return; }
                            alert('Fehler: ' + ((res && res.data && res.data.message) ? res.data.message : 'Unknown error'));
                        });
                });
            });

            document.querySelectorAll('.btn-action-request-cancel').forEach(btn=>{
                btn.addEventListener('click',()=>{
                    if(!confirm('Sind Sie sicher, dass Sie eine Stornierung für diese Buchung anfragen möchten?')) return;
                    
                    const originalText = btn.innerText;
                    btn.innerText = 'Bitte warten...';
                    btn.disabled = true;

                    const d = new URLSearchParams();
                    d.append('action', 'bsbt_request_cancel_booking');
                    d.append('booking_id', btn.dataset.id);
                    d.append('_wpnonce', btn.dataset.nonce);
                    
                    fetch(ajax, { method: 'POST', headers: { 'Content-Type':'application/x-www-form-urlencoded' }, body: d })
                        .then(r=>r.json())
                        .then(res=>{
                            if (res && res.success) { 
                                location.reload(); 
                                return; 
                            }
                            btn.innerText = originalText;
                            btn.disabled = false;
                            alert('Fehler: ' + ((res && res.data && res.data.message) ? res.data.message : 'Unknown error'));
                        })
                        .catch(err => {
                            btn.innerText = originalText;
                            btn.disabled = false;
                            alert('Ein Systemfehler ist aufgetreten.');
                        });
                });
            });

        })();
        </script>

        <?php return ob_get_clean();
    }

    /* ==========================================================================
     * AJAX ACTIONS
     * ========================================================================== */

    public function ajax_confirm() {
        check_ajax_referer('bsbt_owner_action');
        if ( ! $this->is_owner_or_admin() ) wp_send_json_error(['message'=>'No permission']);
        $id = (int)($_POST['booking_id'] ?? 0);
        if ($id<=0) wp_send_json_error(['message'=>'Invalid booking id']);
        if ( ! current_user_can('manage_options') ) {
            if ( $this->get_booking_owner_id($id) !== get_current_user_id() ) {
                wp_send_json_error(['message'=>'Not your booking']);
            }
        }
        $result = BSBT_Owner_Decision_Core::approve_and_send_payment($id);
        if ( ! empty($result['ok']) ) wp_send_json_success(['message' => $result['message'] ?? 'OK']);
        wp_send_json_error(['message' => $result['message'] ?? 'Error']);
    }

    public function ajax_reject() {
        check_ajax_referer('bsbt_owner_action');
        if ( ! $this->is_owner_or_admin() ) wp_send_json_error(['message'=>'No permission']);
        $id = (int)($_POST['booking_id'] ?? 0);
        if ($id<=0) wp_send_json_error(['message'=>'Invalid booking id']);
        if ( ! current_user_can('manage_options') ) {
            if ( $this->get_booking_owner_id($id) !== get_current_user_id() ) {
                wp_send_json_error(['message'=>'Not your booking']);
            }
        }
        $result = BSBT_Owner_Decision_Core::decline_booking($id);
        if ( ! empty($result['ok']) ) wp_send_json_success(['message' => $result['message'] ?? 'OK']);
        wp_send_json_error(['message' => $result['message'] ?? 'Error']);
    }

    public function ajax_request_cancel() {
        check_ajax_referer('bsbt_owner_action');
        if ( ! $this->is_owner_or_admin() ) wp_send_json_error(['message'=>'No permission']);
        
        $id = (int)($_POST['booking_id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message'=>'Invalid booking id']);
        
        $user_id = get_current_user_id();
        
        if ( ! current_user_can('manage_options') ) {
            if ( $this->get_booking_owner_id($id) !== $user_id ) {
                wp_send_json_error(['message'=>'Not your booking']);
            }
        }

        update_post_meta($id, '_bsbt_cancel_requested', '1');

        $admin_email = get_option('admin_email');
        $user = get_userdata($user_id);
        $owner_name = $user ? $user->display_name : 'Vermieter';
        
        $edit_url = admin_url("post.php?post={$id}&action=edit");
        
        $subject = "🚨 Stornierungsanfrage – Buchung #{$id}";
        $message = "Hallo Admin,\n\n"
                 . "Der Vermieter ({$owner_name}) hat eine Stornierung für die Buchung #{$id} angefragt.\n\n"
                 . "Bitte prüfe den Fall und kläre die Situation mit dem Gast.\n\n"
                 . "Link zur Buchung in WP-Admin:\n"
                 . "{$edit_url}\n\n"
                 . "Stay4Fair System";

        wp_mail($admin_email, $subject, $message);

        wp_send_json_success(['message' => 'Anfrage erfolgreich gesendet']);
    }
}

new BSBT_Owner_Bookings();