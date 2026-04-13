<?php
declare(strict_types=1);

namespace StayFlow\Booking;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RefundEngine
 * @version 1.0.5 - WC Status logic fixed. Snapshot saving improved.
 */
final class RefundEngine
{
    public function processCancellation(int $bookingId, string $reason, bool $isFree): array
    {
        try {
            if (!function_exists('MPHB')) throw new \Exception('MotoPress is not active.');

            $booking = \MPHB()->getBookingRepository()->findById($bookingId);
            if (!$booking) throw new \Exception('Booking not found.');

            $order = $this->getWooCommerceOrder($bookingId);

            if ($order instanceof \WC_Order) {
                if ($isFree) {
                    $order->add_order_note('StayFlow: Automated Free Cancellation initiated. Attempting Stripe refund.');
                    $this->executeWooCommerceRefund($order, "Stay4Fair Auto-Cancel: " . $reason);
                    
                    if ($order->get_status() !== 'refunded') {
                        $order->update_status('refunded', 'StayFlow: Order fully refunded.');
                    }
                } else {
                    // ЕСЛИ ШТРАФ 100%: ПРОСТО ОСТАВЛЯЕМ ЗАМЕТКУ. СТАТУС НЕ МЕНЯЕМ.
                    // If 100% penalty: Add note, DO NOT change status to completed.
                    $order->add_order_note('StayFlow: Booking cancelled (Non-refundable). 100% penalty applied. No refund issued.');
                }
            }

            $booking->setStatus('cancelled');
            \MPHB()->getBookingRepository()->save($booking);

            update_post_meta($bookingId, '_bsbt_owner_decision', 'cancelled');

            // КРИТИЧНО ДЛЯ ФИНАНСОВ: Сохраняем refund_type
            $snapshot = get_post_meta($bookingId, '_bsbt_financial_snapshot', true);
            if (!is_array($snapshot)) $snapshot = [];
            
            $snapshot['status']       = 'cancelled';
            $snapshot['refund_type']  = $isFree ? '100%' : '0%';
            $snapshot['cancelled_at'] = current_time('mysql');
            
            update_post_meta($bookingId, '_bsbt_financial_snapshot', $snapshot);

            do_action('stayflow_booking_cancelled', $bookingId, $isFree, $reason, $order ? $order->get_id() : 0);

            return ['success' => true, 'message' => 'Cancelled successfully.'];

        } catch (\Exception $e) {
            error_log('StayFlow Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getWooCommerceOrder(int $bookingId): ?\WC_Order
    {
        if (!function_exists('wc_get_orders')) return null;

        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => '_bsbt_booking_id',
            'meta_value' => $bookingId,
            'status'     => array_keys(wc_get_order_statuses()),
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);
        if (!empty($orders)) return $orders[0];

        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => '_mphb_booking_id',
            'meta_value' => $bookingId,
            'status'     => array_keys(wc_get_order_statuses()),
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);
        
        return !empty($orders) ? $orders[0] : null;
    }

    private function executeWooCommerceRefund(\WC_Order $order, string $reason): void
    {
        $amount = $order->get_total() - $order->get_total_refunded();
        if ($amount <= 0) return;

        $refund = wc_create_refund([
            'amount'         => $amount,
            'reason'         => $reason,
            'order_id'       => $order->get_id(),
            'refund_payment' => true 
        ]);

        if (is_wp_error($refund)) {
            throw new \Exception('WC Refund Error: ' . $refund->get_error_message());
        }
    }
}