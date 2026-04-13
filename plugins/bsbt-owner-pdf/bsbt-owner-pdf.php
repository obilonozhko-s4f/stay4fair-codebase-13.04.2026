<?php
declare(strict_types=1);

/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V2.5.1 - Nullmeldung + Secure PdfEngine)
 * Version: 2.5.1
 * Author: BS Business Travelling / Stay4Fair.com
 */

if (!defined('ABSPATH')) { exit; }

final class BSBT_Owner_PDF {

    const META_LOG            = '_bsbt_owner_pdf_log';
    const META_MAIL_SENT      = '_bsbt_owner_pdf_mail_sent';
    const META_MAIL_SENT_AT   = '_bsbt_owner_pdf_mail_sent_at';
    const META_MAIL_LAST_ERR  = '_bsbt_owner_pdf_mail_last_error';
    const ACF_OWNER_EMAIL_KEY = 'field_68fccdd0cdffc';

    // === SECTION: INIT & HOOKS ===
    // RU: Инициализация и хуки
    // EN: Initialization and hooks

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox'], 10, 2);
        add_action('add_meta_boxes_mphb_booking', [__CLASS__, 'register_metabox_direct'], 10, 1);

        add_action('admin_post_bsbt_owner_pdf_generate', [__CLASS__, 'admin_generate']);
        add_action('admin_post_bsbt_owner_pdf_open',     [__CLASS__, 'admin_open']);
        add_action('admin_post_bsbt_owner_pdf_resend',   [__CLASS__, 'admin_resend']);
        
        add_action('admin_post_bsbt_owner_monthly_report',  [__CLASS__, 'admin_monthly_report']);

        add_action('mphb_booking_status_confirmed', [__CLASS__, 'on_booking_confirmed'], 20, 1);
        add_action('bsbt_owner_booking_approved', [__CLASS__, 'maybe_auto_send'], 20, 1);
    }

    public static function on_booking_confirmed($booking) {
        if (!$booking || !is_object($booking) || !method_exists($booking, 'getId')) return;
        self::maybe_auto_send($booking->getId());
    }

    public static function maybe_auto_send( int $bid ) {
        if ($bid <= 0) return;
        if (get_post_meta($bid, self::META_MAIL_SENT, true) === '1') return;
        if (!function_exists('MPHB')) return;
        $booking = MPHB()->getBookingRepository()->findById($bid);
        if (!$booking) return;

        $res = self::generate_pdf($bid, ['trigger' => 'status_confirmed']);

        if (!empty($res['ok']) && !empty($res['path']) && file_exists($res['path'])) {
            $to = self::get_owner_email($bid);
            if (empty($to) || !is_email($to)) {
                update_post_meta($bid, self::META_MAIL_LAST_ERR, 'Keine E-Mail hinterlegt.');
                return;
            }

            $mail_ok = self::email_owner($bid, $to, $res['path']);

            if ($mail_ok) {
                update_post_meta($bid, self::META_MAIL_SENT, '1');
                update_post_meta($bid, self::META_MAIL_SENT_AT, current_time('mysql'));
                delete_post_meta($bid, self::META_MAIL_LAST_ERR);
            } else {
                update_post_meta($bid, self::META_MAIL_LAST_ERR, 'Fehler beim Senden der E-Mail.');
            }
        }
    }

    // === SECTION: MONTHLY REPORT (PDF & CSV) ===
    // RU: Месячный отчет в форматах PDF и CSV
    // EN: Monthly report in PDF and CSV formats
    
    public static function admin_monthly_report() {
        if (!is_user_logged_in() || !isset($_POST['monthly_report_nonce']) || !wp_verify_nonce($_POST['monthly_report_nonce'], 'bsbt_owner_monthly_report')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $month = (int)($_POST['f_month'] ?? date('n'));
        $year  = (int)($_POST['f_year'] ?? date('Y'));
        $format = sanitize_text_field($_POST['format'] ?? 'pdf');

        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_bsbt_snapshot_owner_payout', 'compare' => 'EXISTS']
            ]
        ];
        
        $query = new WP_Query($args);
        $items = [];
        $total_gross = 0.0;
        $total_prov_gross = 0.0;
        $total_prov_net = 0.0;
        $total_prov_vat = 0.0;
        $total_net = 0.0;
        $has_model_a = false;
        $has_model_b = false;

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $bid = $post->ID;
                
                if (!$is_admin && self::get_owner_id_from_booking($bid) !== $user_id) continue;

                $out = (string)get_post_meta($bid, 'mphb_check_out_date', true);
                if (!$out) continue;
                $out_time = strtotime($out);
                if ((int)date('n', $out_time) !== $month || (int)date('Y', $out_time) !== $year) continue;

                $b = MPHB()->getBookingRepository()->findById($bid);
                $room_type_id = (int) get_post_meta($bid, '_bsbt_snapshot_room_type_id', true);
                
                $guests = 0;
                if ($b && !empty($b->getReservedRooms())) {
                    if (!$room_type_id) { 
                        $room_type_id = (int) $b->getReservedRooms()[0]->getRoomTypeId(); 
                    }
                    foreach ($b->getReservedRooms() as $r) {
                        $guests += (int)$r->getAdults() + (int)$r->getChildren();
                    }
                }
                $guests = max(1, $guests);

                $model = (string) get_post_meta($bid, '_bsbt_snapshot_model', true) ?: 'model_a';
                $gross = (float) get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
                $payout = (float) get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
                $prov_gross = (float) get_post_meta($bid, '_bsbt_snapshot_fee_gross_total', true);
                $prov_net = (float) get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true);
                $prov_vat = (float) get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true);

                $items[] = [
                    'booking_id' => $bid,
                    'apt_title'  => get_the_title($room_type_id),
                    'apt_address'=> get_post_meta($room_type_id, 'address', true),
                    'check_in'   => get_post_meta($bid, 'mphb_check_in_date', true),
                    'check_out'  => $out,
                    'guests'     => $guests,
                    'model'      => $model,
                    'gross'      => $gross,
                    'payout'     => $payout,
                    'prov_gross' => $prov_gross,
                    'prov_net'   => $prov_net,
                    'prov_vat'   => $prov_vat
                ];

                $total_gross += $gross;
                $total_prov_gross += $prov_gross;
                $total_prov_net += $prov_net;
                $total_prov_vat += $prov_vat;
                $total_net += $payout;

                if ($model === 'model_a') $has_model_a = true;
                if ($model === 'model_b') $has_model_b = true;
            }
        }

        // ==========================================================================
        // RU: Разрешаем генерацию пустого PDF (Nullmeldung), убрали wp_die()
        // EN: Allow empty PDF generation (Nullmeldung), removed wp_die()
        // ==========================================================================

        $filename_base = "Monatsabrechnung_{$year}_" . str_pad((string)$month, 2, '0', STR_PAD_LEFT);

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename_base.'.csv"');
            $output = fopen('php://output', 'w');
            
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
            fputcsv($output, ['Buchung-ID', 'Apartment', 'Adresse', 'Check-in', 'Check-out', 'Gaeste', 'Modell', 'Brutto-Gesamt', 'Provision (Brutto)', 'Provision (Netto)', 'Provision (MwSt)', 'Auszahlung (Netto)'], ';');
            
            if (!empty($items)) {
                foreach ($items as $item) {
                    fputcsv($output, [
                        $item['booking_id'],
                        $item['apt_title'],
                        $item['apt_address'],
                        date('d.m.Y', strtotime($item['check_in'])),
                        date('d.m.Y', strtotime($item['check_out'])),
                        $item['guests'],
                        ($item['model'] === 'model_b' ? 'Vermittlung' : 'Direkt'),
                        number_format($item['gross'], 2, ',', ''),
                        number_format($item['prov_gross'], 2, ',', ''),
                        number_format($item['prov_net'], 2, ',', ''),
                        number_format($item['prov_vat'], 2, ',', ''),
                        number_format($item['payout'], 2, ',', '')
                    ], ';');
                }
            } else {
                fputcsv($output, ['Keine Daten fuer diesen Zeitraum. (Nullmeldung)'], ';');
            }

            fputcsv($output, [], ';');
            fputcsv($output, ['GESAMT', '', '', '', '', '', '', 
                number_format($total_gross, 2, ',', ''), 
                number_format($total_prov_gross, 2, ',', ''), 
                number_format($total_prov_net, 2, ',', ''), 
                number_format($total_prov_vat, 2, ',', ''), 
                number_format($total_net, 2, ',', '')
            ], ';');

            fclose($output);
            exit;
        }

        $u = get_userdata($user_id);
        $owner_name = get_user_meta($user_id, 'sf_company_name', true) ?: (get_user_meta($user_id, 'first_name', true) . ' ' . get_user_meta($user_id, 'last_name', true));
        if (!trim($owner_name)) $owner_name = $u->display_name;
        
        $address = get_user_meta($user_id, 'billing_address_1', true) . ', ' . get_user_meta($user_id, 'billing_postcode', true) . ' ' . get_user_meta($user_id, 'billing_city', true);
        $tax_id = get_user_meta($user_id, 'bsbt_tax_number', true) ?: get_user_meta($user_id, 'sf_vat_id', true);
        $iban = get_user_meta($user_id, 'bsbt_iban', true);

        $pdf_data = [
            'month'          => str_pad((string)$month, 2, '0', STR_PAD_LEFT),
            'year'           => $year,
            'items'          => $items,
            'total_gross'    => $total_gross,
            'total_prov'     => $total_prov_gross,
            'total_prov_net' => $total_prov_net,
            'total_prov_vat' => $total_prov_vat,
            'total_net'      => $total_net,
            'has_model_a'    => $has_model_a,
            'has_model_b'    => $has_model_b,
            'owner_name'     => $owner_name,
            'owner_address'  => trim($address, ', '),
            'owner_tax'      => $tax_id,
            'owner_iban'     => $iban,
        ];

        // RU: Проверяем наличие нашего безопасного движка
        if (!class_exists('\StayFlow\Support\PdfEngine')) {
            wp_die('StayFlow PdfEngine is required. Please ensure stayflow-core is active.');
        }

        ob_start();
        $d = $pdf_data;
        include plugin_dir_path(__FILE__) . 'templates/owner-monthly-pdf.php';
        $html = ob_get_clean();

        // RU: Безопасный рендеринг через PdfEngine
        try {
            \StayFlow\Support\PdfEngine::stream($html, $filename_base.'.pdf', 'A4', 'landscape');
            exit;
        } catch (\Throwable $e) {
            wp_die('PDF Error: ' . $e->getMessage());
        }
    }

    private static function get_owner_id_from_booking(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid) return $oid;
        if (!function_exists('MPHB')) return 0;
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return 0;
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return 0;
        return (int) get_post_meta($rooms[0]->getRoomTypeId(), 'bsbt_owner_id', true);
    }

    // === SECTION: SINGLE PDF GENERATION ===
    // RU: Одиночная генерация PDF
    // EN: Single PDF generation

    private static function generate_pdf(int $bid, array $ctx): array {
        if (!class_exists('\StayFlow\Support\PdfEngine')) {
            return ['ok'=>false, 'message'=>'StayFlow PdfEngine missing'];
        }
        
        $data = self::collect_single_data($bid);
        if (empty($data['ok'])) return ['ok'=>false, 'message'=>'Collect data failed'];

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'bsbt-owner-pdf/';
        wp_mkdir_p($dir);
        $path = $dir . 'Owner_PDF_' . $bid . '.pdf';

        try {
            ob_start(); 
            $d = $data['data']; 
            include plugin_dir_path(__FILE__) . 'templates/owner-pdf.php'; 
            $html = ob_get_clean();

            // RU: Безопасное сохранение через PdfEngine
            \StayFlow\Support\PdfEngine::save($html, $path);

            self::log($bid, ['path'=>$path, 'generated_at'=>current_time('mysql'), 'trigger'=>$ctx['trigger']??'ui']);
            return ['ok'=>true, 'path'=>$path];
        } catch (\Throwable $e) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'PDF Error: ' . $e->getMessage());
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    private static function collect_single_data(int $bid): array {
        if (!function_exists('MPHB')) return ['ok'=>false];
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];
        $rt = (int) $rooms[0]->getRoomTypeId();

        $in  = (string) get_post_meta($bid, 'mphb_check_in_date', true);
        $out = (string) get_post_meta($bid, 'mphb_check_out_date', true);
        $n = ($in && $out) ? (int) max(1, (strtotime($out) - strtotime($in)) / 86400) : 0;

        $guests = 0;
        foreach ($rooms as $r) {
            $guests += (int)$r->getAdults() + (int)$r->getChildren();
        }
        $guests = max(1, $guests);

        $model = (string) get_post_meta($bid, '_bsbt_snapshot_model', true) ?: 'model_a';
        $gross = (float) get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
        $payout = (float) get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);

        $pricing = null;
        if ($model === 'model_b') {
            $pricing = [
                'commission_rate'        => (float) get_post_meta($bid, '_bsbt_snapshot_fee_rate', true),
                'commission_net_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true),
                'commission_vat_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true),
                'commission_gross_total' => (float) get_post_meta($bid, '_bsbt_snapshot_fee_gross_total', true),
            ];
        }

        $cc = (string) get_post_meta($bid, 'mphb_country', true);
        $countries = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz'];
        $full_country = $countries[$cc] ?? $cc;

        return ['ok'=>true, 'data'=>[
            'booking_id'        => $bid,
            'business_model'    => ($model === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
            'model_key'         => $model,
            'document_type'     => 'Abrechnung',
            'apt_title'         => get_the_title($rt),
            'apt_id'            => $rt,
            'apt_address'       => get_post_meta($rt, 'address', true),
            'owner_name'        => get_post_meta($rt, 'owner_name', true) ?: '—',
            'check_in'          => $in,
            'check_out'         => $out,
            'nights'            => $n,
            'guests'            => $guests,
            'guest_name'        => trim((string)get_post_meta($bid, 'mphb_first_name', true) . ' ' . (string)get_post_meta($bid, 'mphb_last_name', true)),
            'guest_company'     => get_post_meta($bid, 'mphb_company', true),
            'guest_email'       => get_post_meta($bid, 'mphb_email', true),
            'guest_phone'       => get_post_meta($bid, 'mphb_phone', true),
            'guest_addr'        => get_post_meta($bid, 'mphb_address1', true),
            'guest_zip'         => get_post_meta($bid, 'mphb_zip', true),
            'guest_city'        => get_post_meta($bid, 'mphb_city', true),
            'guest_country'     => $full_country,

            'guest_gross_total' => number_format($gross, 2, ',', '.'),
            'payout'            => number_format($payout, 2, ',', '.'),
            'pricing'           => $pricing,
        ]];
    }

    // === SECTION: METABOX & ADMIN ===
    // RU: Метабокс в админке и роуты
    // EN: Admin metabox and routes
    
    public static function register_metabox($post_type) { if ($post_type === 'mphb_booking') self::add_metabox(); }
    public static function register_metabox_direct() { self::add_metabox(); }
    private static function add_metabox() { add_meta_box('bsbt_owner_pdf', 'BSBT – Owner PDF', [__CLASS__, 'render_metabox'], 'mphb_booking', 'side', 'high'); }
    public static function render_metabox($post) {
        $bid = (int) $post->ID;
        $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color  = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');
        $sent = (get_post_meta($bid, self::META_MAIL_SENT, true) === '1');
        $nonce = wp_create_nonce('bsbt_owner_pdf_' . $bid);

        echo "<div style='font-size:12px;line-height:1.4'>";
        echo "<p><strong>Entscheidung:</strong> <span style='color:$color'>$status</span></p>";
        echo "<p><strong>E-Mail Status:</strong> " . ($sent ? "<span style='color:#2e7d32'>Versendet</span>" : "<span style='color:#f9a825'>Nicht versendet</span>") . "</p>";
        $err = get_post_meta($bid, self::META_MAIL_LAST_ERR, true);
        if (!$sent && $err) echo "<p style='color:#c62828;'><strong>Warnung:</strong> " . esc_html($err) . "</p>";
        echo "<hr>";
        echo "<a class='button' target='_blank' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce") . "'>Öffnen</a> ";
        echo "<a class='button button-primary' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce") . "'>Erzeugen</a> ";
        echo "<a class='button' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce") . "'>Senden</a>";
        echo "</div>";
    }

    public static function admin_generate() { self::guard(); self::generate_pdf((int)($_GET['booking_id'] ?? 0), ['trigger' => 'admin']); wp_redirect(wp_get_referer()); exit; }
    public static function admin_open() {
        self::guard(); $bid = (int)($_GET['booking_id'] ?? 0); $log = get_post_meta($bid, self::META_LOG, true);
        $last = is_array($log) ? end($log) : null;
        if (!$last || empty($last['path']) || !file_exists($last['path'])) wp_die('PDF Datei nicht gefunden.');
        header('Content-Type: application/pdf'); readfile($last['path']); exit;
    }
    public static function admin_resend() { self::guard(); $bid = (int)($_GET['booking_id'] ?? 0); delete_post_meta($bid, self::META_MAIL_SENT); self::maybe_auto_send($bid); wp_redirect(wp_get_referer()); exit; }
    private static function guard() { check_admin_referer('bsbt_owner_pdf_' . (int)($_GET['booking_id'] ?? 0)); }

    // === SECTION: EMAIL & LOGS ===
    // RU: Рассылка и логирование
    // EN: Email sending and logging

    private static function email_owner($bid, $to, $path) {
        if (!$to || !file_exists($path)) return false;
        
        $settings = get_option('stayflow_core_settings', []);
        
        $subject = $settings['owner_pdf']['email_subject'] ?? 'Buchungsbestätigung – Stay4Fair #{booking_id}';
        $msg     = $settings['owner_pdf']['email_body'] ?? "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #{booking_id}.\n\nMit freundlichen Grüßen\nStay4Fair Team";
        
        $subject = str_replace('{booking_id}', (string)$bid, $subject);
        $msg     = str_replace('{booking_id}', (string)$bid, $msg);

        return wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    private static function get_owner_email($bid) {
        $owner_id = self::get_owner_id_from_booking($bid);
        if ($owner_id > 0) {
            $user = get_userdata($owner_id);
            if ($user && is_email($user->user_email)) return $user->user_email;
        }
        return '';
    }

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true);
        if (!is_array($log)) $log = [];
        $log[] = $row;
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();