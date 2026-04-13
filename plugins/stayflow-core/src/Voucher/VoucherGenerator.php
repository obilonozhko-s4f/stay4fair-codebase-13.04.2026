<?php

declare(strict_types=1);

namespace StayFlow\Voucher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.4.4 (Manage Booking Button with Secure Token)
 * RU: Генератор Ваучеров (HTML и PDF). 
 * - [FIX] Логотип конвертируется в Base64 на лету (защита от Image not found в mPDF/DomPDF).
 * - Верстка переведена на классические <table>.
 * - Добавлена кнопка "Manage Booking" отдельным аккуратным блоком внизу с генерацией секьюрного токена.
 */
final class VoucherGenerator
{
    private const BS_EXT_REF_META = '_bs_external_reservation_ref';

    public static function getVoucherNumber(int $bookingId): string
    {
        if (function_exists('bsbt_get_display_booking_ref')) {
            return (string) bsbt_get_display_booking_ref($bookingId);
        }
        
        $ext = trim((string) get_post_meta($bookingId, self::BS_EXT_REF_META, true));
        if ($ext !== '') return $ext;

        $candidateKeys = ['bs_external_reservation', 'external_reservation_number', 'bs_booking_number', 'reservation_number'];
        foreach ($candidateKeys as $key) {
            $val = trim((string) get_post_meta($bookingId, $key, true));
            if ($val !== '') return $val;
        }

        $internal = trim((string) get_post_meta($bookingId, 'bs_internal_booking_number', true));
        if ($internal !== '') return $internal;

        return (string) $bookingId;
    }

    public static function tryLoadPdfEngine(): string
    {
        if (class_exists('\Mpdf\Mpdf')) return 'mpdf';
        if (class_exists('\Dompdf\Dompdf')) return 'dompdf';
        
        $mpdfCandidates = [
            WP_PLUGIN_DIR . '/motopress-hotel-booking-pdf-invoices/vendor/autoload.php', 
            WP_PLUGIN_DIR . '/hotel-booking-pdf-invoices/vendor/autoload.php'
        ];
        
        foreach ($mpdfCandidates as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists('\Mpdf\Mpdf')) return 'mpdf';
            }
        }
        
        $dompdfAutoload = WP_PLUGIN_DIR . '/mphb-invoices/vendors/dompdf/autoload.inc.php';
        if (is_file($dompdfAutoload)) {
            require_once $dompdfAutoload;
            if (class_exists('\Dompdf\Dompdf')) return 'dompdf';
        }
        
        return '';
    }

    public static function generatePdfFile(int $bookingId, string $suffix = ''): string
    {
        if ($bookingId <= 0) return '';
        
        $html = self::renderHtml($bookingId);
        if (!$html) return '';

        $uploadDir = wp_upload_dir();
        $dir = trailingslashit($uploadDir['basedir']) . 'bs-vouchers';
        if (!is_dir($dir)) wp_mkdir_p($dir);

        $suffixStr = $suffix ? '-' . $suffix : '-' . date('Ymd-His');
        $file = trailingslashit($dir) . 'Voucher-' . $bookingId . $suffixStr . '.pdf';

        if ($suffix === 'PAIDEMAIL' && is_file($file) && filesize($file) > 800) {
            return $file;
        }

        $engine = self::tryLoadPdfEngine();
        
        try {
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', '300');
            
            if ($engine === 'mpdf' && class_exists('\Mpdf\Mpdf')) {
                $mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
            } elseif ($engine === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4','portrait');
                $dompdf->render();
                file_put_contents($file, $dompdf->output());
            } else { 
                return ''; 
            }
        } catch (\Throwable $e) { 
            return ''; 
        }

        return (is_file($file) && filesize($file) > 800) ? $file : '';
    }

    public static function renderHtml(int $bookingId): string
    {
        $owner = ['name'=>'','phone'=>'','email'=>'','address'=>'','doorbell'=>''];
        $roomTypeId = 0;
        $model = 'model_a'; 

        if (function_exists('MPHB')) {
            try {
                $booking = \MPHB()->getBookingRepository()->findById($bookingId);
                if ($booking) {
                    $reserved = $booking->getReservedRooms();
                    if (!empty($reserved)) {
                        $first = reset($reserved);
                        $roomTypeId = method_exists($first,'getRoomTypeId') ? (int) $first->getRoomTypeId() : 0;
                        if ($roomTypeId > 0) {
                            $owner['name']     = trim((string)get_post_meta($roomTypeId, 'owner_name', true));
                            $owner['phone']    = trim((string)get_post_meta($roomTypeId, 'owner_phone', true));
                            $owner['email']    = trim((string)get_post_meta($roomTypeId, 'owner_email', true));
                            $owner['address']  = trim((string)get_post_meta($roomTypeId, 'address', true));
                            $owner['doorbell'] = trim((string)get_post_meta($roomTypeId, 'doorbell_name', true));
                            
                            $snapshot = trim((string)get_post_meta($bookingId, '_bsbt_snapshot_model', true));
                            if ($snapshot !== '') {
                                $model = $snapshot === 'model_b' ? 'model_b' : 'model_a';
                            } else {
                                $m = trim((string)get_post_meta($roomTypeId, '_bsbt_business_model', true));
                                if ($m === 'model_b') $model = 'model_b';
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // БЛОК ВЛАДЕЛЬЦА И САППОРТА
        $ownerBlock = '<div style="margin-bottom: 10px;">';
        if ($model === 'model_a') {
            $ownerBlock .= '<div style="font-weight:bold; color:#082567; margin-bottom:4px; border-bottom:1px solid #eee; padding-bottom:4px;">Apartment & On-site Host (Keys & Check-in)</div>';
        } else {
            $ownerBlock .= '<div style="font-weight:bold; color:#082567; margin-bottom:4px; border-bottom:1px solid #eee; padding-bottom:4px;">Apartment & Host</div>';
        }
        
        if ($owner['name'])     $ownerBlock .= '<strong>Owner:</strong> ' . esc_html($owner['name']) . '<br>';
        if ($owner['phone'])    $ownerBlock .= '<strong>Phone:</strong> ' . esc_html($owner['phone']) . '<br>';
        if ($owner['email'])    $ownerBlock .= '<strong>Email:</strong> ' . esc_html($owner['email']) . '<br>';
        if ($owner['address'])  $ownerBlock .= '<br><strong>Apartment address:</strong><br>' . nl2br(esc_html($owner['address'])) . '<br>';
        if ($owner['doorbell']) $ownerBlock .= '<br><strong>Doorbell:</strong> ' . esc_html($owner['doorbell']) . '<br>';
        if ($owner['name'] === '') $ownerBlock .= 'Details will be provided shortly by our team.<br>';
        $ownerBlock .= '</div>';

        // САППОРТ ТОЛЬКО ДЛЯ МОДЕЛИ А
        if ($model === 'model_a') {
            $ownerBlock .= '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">';
            $ownerBlock .= '<div style="font-weight:bold; color:#082567; margin-bottom:4px;">Booking Support (Stay4Fair)</div>';
            $ownerBlock .= '<div style="font-size:10px; color:#666; margin-bottom:4px;">For changes to your reservation or general support, please contact us:</div>';
            $ownerBlock .= '<strong>Phone / WhatsApp:</strong> +49 176 24615269<br>';
            $ownerBlock .= '<strong>Email:</strong> business@stay4fair.com<br>';
            $ownerBlock .= '</div>';
        }

        // ПАРСИНГ ГОСТЕЙ
        $guestNamesArr = [];
        $totalGuests = 0;

        $guestFirst = trim((string)get_post_meta($bookingId,'mphb_first_name',true));
        $guestLast  = trim((string)get_post_meta($bookingId,'mphb_last_name',true));
        $mainGuestName = trim($guestFirst . ' ' . $guestLast);
        if ($mainGuestName !== '') $guestNamesArr[] = $mainGuestName;

        if (isset($booking) && $booking) {
            try {
                $reserved = $booking->getReservedRooms();
                if (!empty($reserved)) {
                    foreach ($reserved as $room) {
                        if (method_exists($room, 'getAdults')) $totalGuests += (int)$room->getAdults();
                        if (method_exists($room, 'getChildren')) $totalGuests += (int)$room->getChildren();
                        
                        if (method_exists($room, 'getGuestName')) {
                            $gName = trim((string)$room->getGuestName());
                            if ($gName !== '') $guestNamesArr[] = $gName;
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        if ($totalGuests <= 0) {
            $totalGuests = (int)get_post_meta($bookingId, 'mphb_adults', true) + (int)get_post_meta($bookingId, 'mphb_children', true);
        }
        if ($totalGuests <= 0) $totalGuests = 1;

        $cleanNames = [];
        foreach ($guestNamesArr as $nameStr) {
            $parts = explode(',', $nameStr);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $cleanNames[] = $p;
            }
        }
        $allGuestNamesString = implode(', ', array_unique($cleanNames));
        if ($allGuestNamesString === '') $allGuestNamesString = 'Guest';

        // ДАТЫ И ВРЕМЯ
        $checkIn  = trim((string)get_post_meta($bookingId,'mphb_check_in_date',true));
        $checkOut = trim((string)get_post_meta($bookingId,'mphb_check_out_date',true));
        $timeIn   = get_post_meta($roomTypeId, '_sf_check_in_time', true) ?: '15:00–23:00';
        $timeOut  = get_post_meta($roomTypeId, '_sf_check_out_time', true) ?: '11:00';

        $policyType = get_post_meta($roomTypeId, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays = (int) get_post_meta($roomTypeId, '_sf_cancellation_days', true);
        $policyReg = get_option('stayflow_registry_policies', []);

        if ($policyType === 'free_cancellation' && $cancelDays > 0) {
            $penaltyDays = $cancelDays - 1;
            $policyRaw = $policyReg['free_cancellation'] ?? "<ul><li>Free cancellation up to <strong>{days} days before arrival</strong>.</li><li>Penalty from <strong>{penalty_days} days</strong>.</li></ul>";
            $policyHtml = str_replace(['{days}', '{penalty_days}'], [(string)$cancelDays, (string)$penaltyDays], $policyRaw);
        } else {
            $policyHtml = $policyReg['non_refundable'] ?? "<p><strong>Non-Refundable</strong></p>";
        }

        // ИНСТРУКЦИИ
        $contentReg = get_option('stayflow_registry_content', []);
        $defaultInstructions = "The keys will be handed over to you at check-in, directly in the apartment (please inform the host about your arrival time).\n" .
                               "Please note: this is a private apartment.\n" .
                               "Light cleaning will be performed every third day. We kindly ask you to keep the apartment in order, too.\n" .
                               "At check-out, you may leave the keys on the table and close the door, or coordinate your check-out time with our manager or the landlord to hand over the keys personally.\n" .
                               "Please handle the apartment and its inventory with care. In case of any damage to the landlord’s property, the guest must compensate the damage.";
                               
        $instructionsRaw = $contentReg['voucher_instructions'] ?? $defaultInstructions;
        $instructions = nl2br(wp_kses_post($instructionsRaw));

        $voucherNo = self::getVoucherNumber($bookingId);

        // ССЫЛКА НА УПРАВЛЕНИЕ БРОНИРОВАНИЕМ (С ГЕНЕРАЦИЕЙ БЕЗОПАСНОГО ТОКЕНА)
        $manageUrl = home_url('/manage-booking/');
        if (class_exists('\StayFlow\Booking\CancellationManager')) {
            $cancelManager = new \StayFlow\Booking\CancellationManager();
            $token = $cancelManager->generateToken($bookingId);
            if ($token !== '') {
                $manageUrl = home_url(sprintf('/manage-booking/?bid=%d&token=%s', $bookingId, $token));
            }
        }

        // =========================================================
        // РАБОТА С ЛОГОТИПОМ (ЛОКАЛЬНЫЙ BASE64 ИЛИ ПУТЬ)
        // =========================================================
        $logoUrl = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';
        $imgSrc = $logoUrl;

        $upload_dir = wp_upload_dir();
        $localLogoPath = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $logoUrl);
        
        if (!file_exists($localLogoPath)) {
            $localLogoPath = ABSPATH . 'wp-content/uploads/2025/12/gorizontal-color-4.png';
        }

        if (file_exists($localLogoPath)) {
            $type = pathinfo($localLogoPath, PATHINFO_EXTENSION);
            $data = @file_get_contents($localLogoPath);
            if ($data) {
                $imgSrc = 'data:image/' . $type . ';base64,' . base64_encode($data);
            }
        }

        // СБОРКА HTML (Безопасная для PDF)
        ob_start(); ?>
        <!doctype html>
        <html>
        <head>
        <meta charset="utf-8">
        <title>Stay4Fair.com — Booking Voucher</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; line-height: 1.4; }
            .h1 { font-size: 20px; font-weight: bold; margin: 0 0 5px; color: #082567; }
            .muted { color: #666; font-size: 11px; margin-bottom: 15px; }
            .box { border: 1px solid #ddd; border-radius: 6px; padding: 10px; background-color: #fafafa; margin-bottom: 10px; }
            .box-white { border: 1px solid #ddd; border-radius: 6px; padding: 10px; background-color: #fff; margin-bottom: 10px; }
            .label { font-weight: bold; color: #082567; margin-bottom: 5px; font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 3px; }
            .kv div { margin: 3px 0; }
            table.topbar { width: 100%; margin-bottom: 15px; border-bottom: 2px solid #082567; padding-bottom: 10px; }
            table.topbar td { vertical-align: middle; }
            .topbar-right { text-align: right; font-size: 11px; color: #333; }
            .legal-disclaimer { margin-top: 15px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 10px; color: #777; text-align: center; }
            table.grid { width: 100%; border-collapse: collapse; border-spacing: 0; margin-bottom: 10px; }
            table.grid td { vertical-align: top; }
        </style>
        </head>
        <body>

            <table class="topbar" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="width: 50%; text-align: left;">
                        <img src="<?php echo esc_attr($imgSrc); ?>" alt="Stay4Fair" height="40" style="height: 40px; width: auto; max-width: 250px;">
                    </td>
                    <td style="width: 50%;" class="topbar-right">
                        E-mail: business@stay4fair.com<br>
                        WhatsApp: +49 176 24615269<br>
                        stay4fair.com
                    </td>
                </tr>
            </table>

            <div class="h1">Booking Voucher</div>
            <div class="muted">Voucher No: <?php echo esc_html($voucherNo); ?> &middot; Booking ID: <?php echo (int)$bookingId; ?></div>

            <table class="grid" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="width: 55%; padding-right: 10px;">
                        <div class="box-white">
                            <div class="label">Guest Information</div>
                            <div class="kv">
                                <div><strong>Name:</strong> <?php echo esc_html($allGuestNamesString); ?></div>
                                <div><strong>Total guests:</strong> <?php echo (int)$totalGuests; ?></div>
                            </div>
                        </div>
                        <div class="box-white">
                            <div class="label">Stay Details</div>
                            <div class="kv">
                                <div><strong>Check-in:</strong> <?php echo esc_html($checkIn); ?> (from <?php echo esc_html($timeIn); ?>)</div>
                                <div><strong>Check-out:</strong> <?php echo esc_html($checkOut); ?> (until <?php echo esc_html($timeOut); ?>)</div>
                            </div>
                        </div>
                    </td>
                    
                    <td style="width: 45%;">
                        <div class="box">
                            <?php echo $ownerBlock; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="box-white">
                <div class="label">Instructions</div>
                <div style="font-size:11px;"><?php echo $instructions; ?></div>
            </div>

            <div class="box-white">
                <div class="label">Cancellation Policy Details</div>
                <div style="font-size:11px;"><?php echo wp_kses_post($policyHtml); ?></div>
            </div>

            <div class="box-white" style="text-align:center; background-color:#f8f9fa; margin-top:15px; padding:15px;">
                <div style="font-size:14px; font-weight:bold; color:#082567; margin-bottom:5px;">Manage Your Booking</div>
                <div style="font-size:11px; color:#555; margin-bottom:12px;">Need to change your dates, check cancellation details, or cancel your reservation?</div>
                <a href="<?php echo esc_url($manageUrl); ?>" style="display:inline-block; padding:10px 24px; background-color:#082567; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:bold; font-size:12px;">
                    Manage Booking
                </a>
            </div>

            <div class="legal-disclaimer">
                <strong>Important Note:</strong> This document is an arrival guide and booking voucher. It does not constitute a tax invoice or payment receipt.
            </div>

        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}