<?php

declare(strict_types=1);

namespace StayFlow\Voucher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.3.0
 * RU: Логика отправки ваучеров. Идеальный атомарный Lock с обработкой протухших воркеров (Race Condition fix).
 * EN: Voucher Sending Logic. Perfect atomic Lock with stale worker handling (Race Condition fix).
 */
final class VoucherSender
{
    private const PAID_SENT_META = '_bsbt_voucher_paid_email_sent';
    private const PAID_LOCK_META = '_bsbt_voucher_paid_email_lock';

    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'triggerVoucherProcess'], 20);
        add_action('woocommerce_order_status_changed', [$this, 'triggerOnStatusChange'], 20, 3);
        add_action('bsbt_send_voucher_paid_email_action', [$this, 'processWorker'], 10, 1);
        add_action('mphb_booking_status_changed', [$this, 'autoSendOnFullPayment'], 10, 3);
        add_filter('woocommerce_email_attachments', [$this, 'attachToWooEmail'], 10, 3);
    }

    public function triggerOnStatusChange($orderId, $oldStatus, $newStatus): void
    {
        if ($newStatus === 'processing') {
            $this->triggerVoucherProcess($orderId);
        }
    }

    public function triggerVoucherProcess($orderId): void
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) return;

        $delay = 10;

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + $delay,
                'bsbt_send_voucher_paid_email_action',
                ['order_id' => $orderId, 'attempt' => 1],
                'bsbt_voucher'
            );
        } else {
            $this->processWorker(['order_id' => $orderId, 'attempt' => 1]);
        }
    }

    public function processWorker($args): void
    {
        $orderId = 0;
        $attempt  = 1;

        if (is_array($args) && isset($args['order_id'])) {
            $orderId = (int)$args['order_id'];
            $attempt  = isset($args['attempt']) ? (int)$args['attempt'] : 1;
        } elseif (is_numeric($args)) {
            $orderId = (int)$args;
        }

        if ($orderId <= 0) return;

        $bookingId = $this->findBookingIdForOrder($orderId);
        if ($bookingId <= 0) {
            $this->scheduleRetry($orderId, $attempt + 1, 'booking_id_not_found');
            return;
        }

        if (get_post_meta($bookingId, self::PAID_SENT_META, true)) {
            return;
        }

        // ==========================================
        // RU: АТОМАРНЫЙ LOCK (С ЗАЩИТОЙ ОТ ГОНОК И ЗАВИСАНИЙ)
        // EN: ATOMIC LOCK (WITH RACE CONDITION AND STALL PROTECTION)
        // ==========================================
        $timestamp = time();
        $lockClaimed = add_post_meta($bookingId, self::PAID_LOCK_META, (string) $timestamp, true);

        if (!$lockClaimed) {
            // Лок уже существует, проверяем на протухание (TTL 10 минут)
            $existingLock = (int) get_post_meta($bookingId, self::PAID_LOCK_META, true);
            if ($existingLock > 0 && ($timestamp - $existingLock) > 600) {
                // Удаляем протухший лок и СНОВА пробуем захватить атомарно
                delete_post_meta($bookingId, self::PAID_LOCK_META);
                $lockClaimed = add_post_meta($bookingId, self::PAID_LOCK_META, (string) $timestamp, true);
            }
        }

        if (!$lockClaimed) {
            // Другой воркер сейчас активно обрабатывает эту бронь
            return;
        }

        try {
            $pdf = VoucherGenerator::generatePdfFile($bookingId, 'PAIDEMAIL');
            if (!$pdf) {
                self::logSend($bookingId, ['to'=>'system', 'subject'=>'Separate voucher email', 'source'=>'paid-email', 'status'=>'fail', 'error'=>'PDF not generated']);
                $this->scheduleRetry($orderId, $attempt + 1, 'pdf_not_generated');
                return;
            }

            $to = $this->getGuestEmail($bookingId);
            if (!$to) {
                self::logSend($bookingId, ['to'=>'system', 'subject'=>'Separate voucher email', 'source'=>'paid-email', 'status'=>'fail', 'error'=>'Guest email missing']);
                $this->scheduleRetry($orderId, $attempt + 1, 'guest_email_missing');
                return;
            }

            $voucherNo = VoucherGenerator::getVoucherNumber($bookingId);
            $subject = sprintf('Your Booking Voucher %s — Stay4Fair', $voucherNo);
            $message = VoucherGenerator::renderHtml($bookingId);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            $fromNameCb  = function() { return 'Stay4Fair Reservations'; };
            $fromEmailCb = function() { return 'business@stay4fair.com'; };

            add_filter('wp_mail_from_name', $fromNameCb, 999);
            add_filter('wp_mail_from', $fromEmailCb, 999);

            $sent = false;
            try {
                $sent = wp_mail($to, $subject, $message, $headers, [$pdf]);
            } finally {
                // Гарантированное удаление фильтров
                remove_filter('wp_mail_from_name', $fromNameCb, 999);
                remove_filter('wp_mail_from', $fromEmailCb, 999);
            }

            self::logSend($bookingId, [
                'to'      => $to,
                'subject' => $subject,
                'source'  => 'paid-email',
                'status'  => $sent ? 'ok' : 'fail',
                'error'   => $sent ? '' : 'wp_mail returned false'
            ]);

            if ($sent) {
                update_post_meta($bookingId, self::PAID_SENT_META, 1);
            } else {
                $this->scheduleRetry($orderId, $attempt + 1, 'wp_mail_failed');
            }

        } finally {
            // RU: Очистка временного лока при завершении
            // EN: Cleanup temporary lock on completion
            delete_post_meta($bookingId, self::PAID_LOCK_META);
        }
    }

    private function scheduleRetry(int $orderId, int $attempt, string $reason): void
    {
        $delays = [30, 120, 600];
        if ($attempt >= 4) return;

        $delay = $delays[$attempt - 1] ?? 120;

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + $delay,
                'bsbt_send_voucher_paid_email_action',
                ['order_id' => $orderId, 'attempt' => $attempt],
                'bsbt_voucher'
            );
        }
    }

    public function autoSendOnFullPayment($booking, $newStatus, $oldStatus = null): void
    {
        $bookingId = is_object($booking) && method_exists($booking, 'getId') ? (int) $booking->getId() : (int) $booking;
        if ($bookingId <= 0) return;

        if (get_post_meta($bookingId, '_bsbt_voucher_sent', true)) return;

        $total = 0.0;
        $paid  = 0.0;

        if (function_exists('MPHB')) {
            try {
                $entity = \MPHB()->getBookingRepository()->findById($bookingId);
                if ($entity) {
                    if (method_exists($entity, 'getTotalPrice')) $total = (float) $entity->getTotalPrice();
                    if (method_exists($entity, 'getPaidAmount')) $paid = (float) $entity->getPaidAmount();
                }
            } catch (\Throwable $e) {}
        }

        if ($total <= 0) $total = (float) get_post_meta($bookingId, 'mphb_total_price', true);
        if ($paid <= 0)  $paid  = (float) get_post_meta($bookingId, 'mphb_total_price_paid', true);

        if ($total <= 0 || ($paid + 0.01 < $total)) return;

        $this->sendManualOrAutoEmail($bookingId, 'auto:paid', null);
    }

    public function attachToWooEmail($attachments, $emailId, $order): array
    {
        if ($emailId !== 'customer_processing_order' || !is_object($order)) return $attachments;

        $orderId = $order->get_id();
        if (!$orderId) return $attachments;

        $bookingId = $this->findBookingIdForOrder($orderId);
        if ($bookingId <= 0) return $attachments;

        if (defined('BSBT_FLOW_META')) {
            $flow = get_post_meta($bookingId, constant('BSBT_FLOW_META'), true);
            if ($flow !== 'auto') return $attachments;
        }

        $pdf = VoucherGenerator::generatePdfFile($bookingId);
        if (!$pdf || !is_file($pdf)) return $attachments;

        if (!in_array($pdf, $attachments, true)) {
            $attachments[] = $pdf;
        }

        self::logSend($bookingId, [
            'to'      => 'Woo customer',
            'subject' => 'Customer processing order',
            'source'  => 'woo:processing:attach',
            'status'  => 'ok',
            'error'   => ''
        ]);

        return $attachments;
    }

    public function sendManualOrAutoEmail(int $bookingId, string $source = 'auto', ?string $overrideEmail = null): array
    {
        if ($bookingId <= 0) return ['error' => 'Invalid booking_id'];

        $guestEmail = $this->getGuestEmail($bookingId);
        
        if (!empty($overrideEmail)) {
            if (!is_email($overrideEmail)) {
                return self::logSend($bookingId, ['to'=>$overrideEmail,'subject'=>'(not sent)','source'=>$source,'status'=>'fail','error'=>'Override email invalid']);
            }
            $guestEmail = $overrideEmail;
        }

        if (empty($guestEmail) || !is_email($guestEmail)) {
            return self::logSend($bookingId, ['to'=>$guestEmail ?: '(empty)','subject'=>'(not sent)','source'=>$source,'status'=>'fail','error'=>'Guest email missing']);
        }

        $voucherNo = VoucherGenerator::getVoucherNumber($bookingId);
        $subject = sprintf('[Stay4Fair.com] Voucher — Booking %s', $voucherNo);
        $body    = VoucherGenerator::renderHtml($bookingId);
        
        $pdf = VoucherGenerator::generatePdfFile($bookingId);
        $attachments = ($pdf && is_file($pdf)) ? [$pdf] : [];

        $fromNameCb  = function() { return 'Stay4Fair Reservations'; };
        $fromEmailCb = function() { return 'business@stay4fair.com'; };

        add_filter('wp_mail_from_name', $fromNameCb, 999);
        add_filter('wp_mail_from', $fromEmailCb, 999);
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = false;
        try {
            $sent = wp_mail($guestEmail, $subject, $body, $headers, $attachments);
        } finally {
            remove_filter('wp_mail_from_name', $fromNameCb, 999);
            remove_filter('wp_mail_from', $fromEmailCb, 999);
        }

        $entry = self::logSend($bookingId, [
            'to'      => $guestEmail,
            'subject' => $subject,
            'source'  => $source,
            'status'  => $sent ? 'ok' : 'fail',
            'error'   => $sent ? '' : 'wp_mail returned false'
        ]);

        if ($sent && empty($entry['error'])) {
            update_post_meta($bookingId, '_bsbt_voucher_sent', 1);
            update_post_meta($bookingId, '_bsbt_voucher_last_source', $source);
        }

        return $entry;
    }

    public static function logSend(int $bookingId, array $entry): array
    {
        $log = get_post_meta($bookingId, '_bsbt_voucher_log', true);
        if (!is_array($log)) $log = [];
        
        $entry = wp_parse_args($entry, [
            'time' => current_time('mysql'),
            'to' => '', 'subject' => '', 'source' => '',
            'status' => 'ok', 'error' => ''
        ]);
        
        $log[] = $entry;
        update_post_meta($bookingId, '_bsbt_voucher_log', $log);
        return $entry;
    }

    private function findBookingIdForOrder(int $orderId): int
    {
        if (!function_exists('wc_get_order')) return 0;
        $order = wc_get_order($orderId);
        if (!$order) return 0;

        foreach ($order->get_items() as $item) {
            $paymentId = (int) $item->get_meta('_mphb_payment_id', true);
            if ($paymentId > 0) {
                $bookingId = (int) get_post_meta($paymentId, '_mphb_booking_id', true);
                if (!$bookingId) $bookingId = (int) get_post_meta($paymentId, 'mphb_booking_id', true);
                if ($bookingId > 0) return $bookingId;
            }
        }
        return 0;
    }

    private function getGuestEmail(int $bookingId): string
    {
        $email = trim((string) get_post_meta($bookingId, 'mphb_email', true));
        if ($email && is_email($email)) return $email;

        if (function_exists('MPHB')) {
            try {
                $entity = \MPHB()->getBookingRepository()->findById($bookingId);
                if ($entity && method_exists($entity, 'getCustomer')) {
                    $c = $entity->getCustomer();
                    if ($c && method_exists($c, 'getEmail')) {
                        $e = trim((string)$c->getEmail());
                        if ($e && is_email($e)) return $e;
                    }
                }
            } catch (\Throwable $e) {}
        }
        return '';
    }
}