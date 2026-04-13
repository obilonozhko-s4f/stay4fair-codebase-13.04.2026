<?php
declare(strict_types=1);

namespace StayFlow\Booking;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class CancellationNotificationHandler
 * @version 1.1.9 - Dynamic filenames for Storno PDFs
 */
final class CancellationNotificationHandler
{
    private string $logoUrl = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';

    public function register(): void
    {
        add_action('stayflow_booking_cancelled', [$this, 'processNotifications'], 10, 4);
        add_filter('stayflow_generate_storno_pdf', [$this, 'generateStornoPdf'], 10, 4);
        add_action('stayflow_request_date_change_notify', [$this, 'notifyAdminDateChange'], 10, 3);
    }

    public function generateStornoPdf(string $path, int $bookingId, bool $isFree, string $lang = 'de'): string
    {
        if (!class_exists('\StayFlow\Voucher\VoucherGenerator') || !function_exists('MPHB')) return '';

        $booking = \MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking) return '';

        $original_total = (float) get_post_meta($bookingId, '_bsbt_snapshot_guest_total', true);
        if (!$original_total) $original_total = (float) $booking->getTotalPrice();

        $uploadDir = wp_upload_dir();
        $stornoDir = trailingslashit($uploadDir['basedir']) . 'bsbt-owner-pdf/storno/';
        wp_mkdir_p($stornoDir);
        
        // Разные имена файлов в зависимости от языка
        $filename = $lang === 'en' 
            ? 'Cancellation_Confirmation_' . $bookingId . '.pdf' 
            : 'Stornorechnung_' . $bookingId . '.pdf';
            
        $pdfPath = $stornoDir . $filename;

        try {
            $engine = \StayFlow\Voucher\VoucherGenerator::tryLoadPdfEngine();
            ob_start();
            
            $booking_id = $bookingId;
            $is_free = $isFree;
            
            $templatePath = WP_PLUGIN_DIR . '/bsbt-owner-pdf/templates/storno-pdf.php';
            
            if (file_exists($templatePath)) include $templatePath;
            else return ''; 

            $html = ob_get_clean();

            if ($engine === 'mpdf' && class_exists('\Mpdf\Mpdf')) {
                $mpdf = new \Mpdf\Mpdf(['format' => 'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($pdfPath, 'F');
            } elseif ($engine === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
                $dom = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dom->loadHtml($html, 'UTF-8');
                $dom->render();
                file_put_contents($pdfPath, $dom->output());
            }

            return file_exists($pdfPath) ? $pdfPath : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function processNotifications(int $bookingId, bool $isFree, string $reason, int $orderId): void
    {
        try {
            if (!function_exists('MPHB')) return;
            $booking = \MPHB()->getBookingRepository()->findById($bookingId);
            if (!$booking) return;

            $guestEmail = get_post_meta($bookingId, 'mphb_email', true);
            $guestName  = get_post_meta($bookingId, 'mphb_first_name', true) . ' ' . get_post_meta($bookingId, 'mphb_last_name', true);
            
            $rooms = $booking->getReservedRooms();
            $apartmentId = !empty($rooms) ? (int)$rooms[0]->getRoomTypeId() : 0;
            $ownerEmail  = $apartmentId ? get_userdata(get_post($apartmentId)->post_author)->user_email : '';
            $adminEmail  = get_option('admin_email');

            // Генерируем 2 разных PDF-файла (с правильными названиями)
            $stornoPdfPathEN = $this->generateStornoPdf('', $bookingId, $isFree, 'en');
            $stornoPdfPathDE = $this->generateStornoPdf('', $bookingId, $isFree, 'de');

            // Шлем письмо гостю ТОЛЬКО если отмена платная (WooCommerce тут молчит)
            if (!$isFree) {
                $this->sendGuestEmail($guestEmail, $guestName, $bookingId, $isFree, $stornoPdfPathEN);
            }
            
            if ($ownerEmail) $this->sendOwnerEmail($ownerEmail, $guestName, $bookingId, $isFree, $reason, $stornoPdfPathDE);
            $this->sendAdminEmail($adminEmail, $bookingId, $reason, $isFree);

        } catch (\Exception $e) {}
    }

    public function notifyAdminDateChange(int $bookingId, string $email, string $comment): void
    {
        $editUrl = admin_url("post.php?post={$bookingId}&action=edit");
        $subject = "Date Change Request - Booking #{$bookingId}";
        $body = "<h2 style='color:#082567;'>Guest requested a date change</h2><p><strong>Booking ID:</strong> {$bookingId}</p><p><strong>Guest Email:</strong> {$email}</p><div style='background:#f5f5f5; padding:15px; margin:15px 0; border-left:4px solid #082567;'><em>\"" . esc_html($comment) . "\"</em></div><p><a href='{$editUrl}' style='display:inline-block; padding:12px 24px; background-color:#082567; color:#E0B849; text-decoration:none; font-weight:bold; border-radius:8px;'>View Booking in Admin</a></p>";
        $this->sendHtmlEmail(get_option('admin_email'), $subject, $body);
    }

    private function sendGuestEmail(string $to, string $name, int $bookingId, bool $isFree, string $pdf): void
    {
        $subject = "Cancellation Confirmation - Booking #{$bookingId}";
        $homeUrl = site_url();
        $statusText = "<span style='color:#c62828; font-weight:bold;'>Non-Refundable (100% Penalty Applied)</span>";

        $body = "<h2 style='color:#082567; margin-top:0;'>Booking Cancelled</h2><p>Dear {$name},</p><p>This email confirms that your booking <strong>#{$bookingId}</strong> has been successfully cancelled.</p><p><strong>Cancellation Status:</strong> {$statusText}</p><p style='color:#666;'>As the free cancellation period had expired, a 100% cancellation fee has been applied according to the booking terms. No refund will be issued. Please find your official cancellation invoice (Cancellation Confirmation) attached.</p>";
        $body .= "<div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center;'><p style='font-size: 16px; color: #333;'><strong>We are truly sorry that you had to cancel your stay.</strong></p><p style='color: #666; margin-bottom: 20px;'>We hope to have the pleasure of welcoming you again in the future.</p><a href='{$homeUrl}' style='display:inline-block; padding:14px 28px; background-color:#082567; color:#E0B849; text-decoration:none; font-weight:bold; border-radius:8px; font-size:16px; border:1px solid #000; border-bottom:4px solid #000;'>Visit Stay4Fair.com</a></div>";
        
        $attachments = ($pdf && file_exists($pdf)) ? [$pdf] : [];
        $this->sendHtmlEmail($to, $subject, $body, $attachments);
    }

    private function sendOwnerEmail(string $to, string $guestName, int $bookingId, bool $isFree, string $reason, string $pdf): void
    {
        $subject = "Stornierung - Buchung #{$bookingId}";
        $dashboardUrl = site_url('/owner-bookings/');
        $statusText = $isFree ? "<span style='color:#c62828; font-weight:bold;'>Kostenlos storniert (Kalender freigegeben)</span>" : "<span style='color:#2e7d32; font-weight:bold;'>Stornogebühr 100% (Kalender freigegeben, Einnahme gesichert)</span>";

        $body = "<h2 style='color:#082567; margin-top:0;'>Buchung storniert</h2><p>Guten Tag,</p><p>Die Buchung <strong>#{$bookingId}</strong> (Gast: {$guestName}) wurde durch den Gast storniert.</p><p><strong>Grund:</strong> " . esc_html($reason) . "</p><p><strong>Status:</strong> {$statusText}</p><p>Die entsprechende Stornorechnung für Ihre Unterlagen befindet sich im Anhang.</p>";
        $body .= "<div style='margin-top: 30px; text-align: center;'><a href='{$dashboardUrl}' style='display:inline-block; padding:14px 28px; background-color:#082567; color:#E0B849; text-decoration:none; font-weight:bold; border-radius:8px; font-size:16px; border-bottom:4px solid #000;'>Buchung im Portal ansehen</a></div>";

        $attachments = ($pdf && file_exists($pdf)) ? [$pdf] : [];
        $this->sendHtmlEmail($to, $subject, $body, $attachments);
    }

    private function sendAdminEmail(string $to, int $bookingId, string $reason, bool $isFree): void
    {
        $editUrl = admin_url("post.php?post={$bookingId}&action=edit");
        $subject = "Admin Alert: Booking Cancelled (#{$bookingId})";
        $body = "<h2 style='color:#c62828;'>Booking Cancelled</h2><ul><li><strong>Booking ID:</strong> {$bookingId}</li><li><strong>Reason:</strong> " . esc_html($reason) . "</li><li><strong>Refund Type:</strong> " . ($isFree ? "Full Refund (Free)" : "No Refund (100% Penalty)") . "</li></ul><p><a href='{$editUrl}' style='display:inline-block; padding:10px 20px; background:#082567; color:#E0B849; text-decoration:none; font-weight:bold; border-radius:4px;'>Open Booking in WP Admin</a></p>";
        $this->sendHtmlEmail($to, $subject, $body);
    }

    private function sendHtmlEmail(string $to, string $subject, string $content, array $attachments = []): void
    {
        $html = "<div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 12px; overflow: hidden; background:#fff;'><div style='background: #f9f9f9; padding: 25px; text-align: center; border-bottom: 1px solid #ddd;'><img src='{$this->logoUrl}' alt='Stay4Fair' style='max-height: 45px;'></div><div style='padding: 35px 30px;'>{$content}</div><div style='background: #082567; color: #E0B849; padding: 20px; text-align: center; font-size: 13px;'>Stay4Fair.com &copy; " . date('Y') . "</div></div>";
        wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $attachments);
    }
}