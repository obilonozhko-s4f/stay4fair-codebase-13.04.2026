<?php
/**
 * Core логика принятия решений владельцем.
 * Core logic for owner decision making.
 *
 * Version V10.31.0 - Unsuppress emails before sending custom voucher.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BSBT_Owner_Decision_Core {

    const META_DECISION       = '_bsbt_owner_decision';
    const META_DECISION_TIME  = '_bsbt_owner_decision_time';
    const META_BSBT_REF       = '_bsbt_booking_id';
    const META_PAYMENT_ISSUE  = '_bsbt_payment_issue';
    const META_DECISION_LOCK  = '_bsbt_owner_decision_lock';
    const META_CAPTURE_STARTED_AT   = '_bsbt_capture_started_at';
    const META_CAPTURE_COMPLETED_AT = '_bsbt_capture_completed_at';
    const META_ENGINE_VERSION = '_bsbt_decision_engine_version';

    private static function hardening_log( int $booking_id, string $reason, int $order_id = 0 ): void {
        error_log(sprintf(
            '[BSBT_DECISION_HARDENING] booking_id=%d order_id=%d reason=%s timestamp=%s',
            $booking_id, $order_id, $reason, current_time('mysql')
        ));
    }

    private static function is_booking_pending( int $booking_id ): bool {
        if ( function_exists('MPHB') ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking && method_exists( $booking, 'getStatus' ) ) {
                    $status = (string) $booking->getStatus();
                    if ( strpos( $status, 'mphb-' ) === 0 ) $status = substr( $status, 5 );
                    return in_array( $status, ['pending', 'pending-user', 'pending-payment'], true );
                }
            } catch ( \Throwable $e ) {}
        }
        $post_status = (string) get_post_status( $booking_id );
        return in_array( $post_status, [ 'pending', 'mphb-pending' ], true );
    }

    private static function terminal_guard( int $booking_id ): array {
        if ( $booking_id <= 0 ) return ['ok' => false, 'message' => 'Invalid ID'];
        if ( (string) get_post_meta( $booking_id, self::META_DECISION, true ) !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists' );
            return ['ok' => false, 'message' => 'Already processed'];
        }
        if ( (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true ) !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }
        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }
        return ['ok' => true];
    }

    private static function acquire_lock( int $booking_id ): array {
        $lock_token = wp_generate_uuid4();
        if ( ! add_post_meta( $booking_id, self::META_DECISION_LOCK, $lock_token, true ) ) {
            self::hardening_log( $booking_id, 'lock_failed' );
            return [ 'ok' => false, 'token' => '' ];
        }
        return [ 'ok' => true, 'token' => $lock_token ];
    }

    private static function release_lock( int $booking_id, string $lock_token ): void {
        if ( $lock_token === '' ) return;
        if ( (string) get_post_meta( $booking_id, self::META_DECISION_LOCK, true ) === $lock_token ) {
            delete_post_meta( $booking_id, self::META_DECISION_LOCK );
        }
    }

    private static function post_lock_guard( int $booking_id ): array {
        if ( (string) get_post_meta( $booking_id, self::META_DECISION, true ) !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists_post_lock' );
            return ['ok' => false, 'message' => 'Already processed'];
        }
        if ( (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true ) !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight_post_lock' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }
        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }
        return ['ok' => true];
    }

    private static function assert_transition_allowed( int $booking_id, string $mode ): array {
        if ( $booking_id <= 0 ) return ['ok' => false, 'message' => 'Invalid ID'];
        if ( (string) get_post_meta( $booking_id, self::META_DECISION, true ) !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists_invariant' );
            return ['ok' => false, 'message' => 'Already processed'];
        }
        if ( (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true ) !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight_invariant' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }
        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }
        
        $flow_mode = get_post_meta( $booking_id, '_bsbt_flow_mode', true );
        if ($flow_mode !== 'manual') {
            $order = self::find_order_for_booking( $booking_id );
            if ( $order instanceof WC_Order && $order->is_paid() ) {
                self::hardening_log( $booking_id, 'order_already_paid_invariant', (int) $order->get_id() );
                return ['ok' => false, 'message' => 'Order already paid'];
            }
        }
        return ['ok' => true];
    }

    private static function stamp_engine_version( int $booking_id ): void {
        add_post_meta( $booking_id, self::META_ENGINE_VERSION, '10.31.0', true );
    }

    private static function clear_capture_started_on_failure( int $booking_id ): void {
        delete_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT );
    }

    public static function approve_and_send_payment( int $booking_id ): array {
        $guard = self::terminal_guard( $booking_id );
        if ( empty( $guard['ok'] ) ) return ['ok'=>false,'message'=> $guard['message']];

        $lock = self::acquire_lock( $booking_id );
        if ( empty( $lock['ok'] ) ) return ['ok'=>false,'message'=>'Already locked'];
        $lock_token = (string) ( $lock['token'] ?? '' );

        try {
            $post_lock = self::post_lock_guard( $booking_id );
            if ( empty( $post_lock['ok'] ) ) return ['ok'=>false,'message'=> $post_lock['message']];

            $inv = self::assert_transition_allowed( $booking_id, 'approve' );
            if ( empty( $inv['ok'] ) ) return ['ok'=>false,'message'=> $inv['message']];

            self::stamp_engine_version( $booking_id );

            $flow_mode = get_post_meta( $booking_id, '_bsbt_flow_mode', true );
            $is_manual = ($flow_mode === 'manual');
            
            if ( $is_manual ) {
                // РУЧНАЯ БРОНЬ (MANUAL FLOW)
                add_post_meta( $booking_id, self::META_CAPTURE_COMPLETED_AT, time(), true );
                delete_post_meta( $booking_id, self::META_PAYMENT_ISSUE );
                update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );
                
                $written = add_post_meta( $booking_id, self::META_DECISION, 'approved', true );
                if ( ! $written ) return ['ok'=>false,'message'=>'Decision write failed'];

                // 1. Глушим стандартные письма MPHB
                $GLOBALS['bsbt_suppress_booking_email_for'] = $booking_id;

                self::set_mphb_status_safe( $booking_id, 'confirmed' );
                do_action( 'bsbt_owner_booking_approved', $booking_id );

                // 2. РАЗГЛУШАЕМ ПИСЬМА (чтобы наш кастомный ваучер прошел)
                $GLOBALS['bsbt_suppress_booking_email_for'] = 0;

                // 3. Отправляем ваучер
                if (class_exists('\StayFlow\Voucher\VoucherSender')) {
                    $sender = new \StayFlow\Voucher\VoucherSender();
                    $sender->sendManualOrAutoEmail($booking_id, 'manual:approved');
                }

                error_log('[BSBT SUCCESS] Booking #' . $booking_id . ' approved (Manual Flow). Voucher sent, WC bypassed.');
                return ['ok'=>true,'paid'=>true,'order_id'=>0,'message'=>'Manual Approved & Voucher Sent'];
            }

            // АВТОМАТИЧЕСКАЯ БРОНЬ (AUTO FLOW)
            $order = self::find_order_for_booking( $booking_id );
            if ( ! ( $order instanceof WC_Order ) ) {
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                return ['ok'=>false,'message'=>'Order not found'];
            }

            $order_id = (int) $order->get_id();
            $order->update_meta_data( self::META_BSBT_REF, $booking_id );
            $order->save();

            if ( ! $order->is_paid() ) {
                if ( ! add_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, time(), true ) ) {
                    return ['ok'=>false,'message'=>'Capture already started'];
                }
                $gateway = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order( $order ) : null;
                try {
                    if ( $gateway && method_exists( $gateway, 'capture_payment' ) ) {
                        $gateway->capture_payment( $order_id );
                    }
                    $order = wc_get_order( $order_id );
                    if ( $order && ! $order->is_paid() ) $order->payment_complete();
                } catch ( \Throwable $e ) {
                    self::clear_capture_started_on_failure( $booking_id );
                    throw $e;
                }
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || ! $order->is_paid() ) {
                self::clear_capture_started_on_failure( $booking_id );
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                return ['ok'=>false,'message'=>'Payment not captured'];
            }

            add_post_meta( $booking_id, self::META_CAPTURE_COMPLETED_AT, time(), true );
            delete_post_meta( $booking_id, self::META_PAYMENT_ISSUE );
            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            $written = add_post_meta( $booking_id, self::META_DECISION, 'approved', true );
            if ( ! $written ) return ['ok'=>false,'message'=>'Decision write failed'];

            self::set_mphb_status_safe( $booking_id, 'confirmed' );
            do_action( 'bsbt_owner_booking_approved', $booking_id );

            error_log('[BSBT SUCCESS] Booking #' . $booking_id . ' approved | Order #' . $order_id);

            return ['ok'=>true,'paid'=>true,'order_id'=>$order_id,'message'=>'Approved'];

        } catch ( \Throwable $e ) {
            error_log('[BSBT APPROVE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
            self::clear_capture_started_on_failure( $booking_id );
            return ['ok'=>false,'message'=>'Capture error'];
        } finally {
            self::release_lock( $booking_id, $lock_token );
        }
    }

    public static function decline_booking( int $booking_id ): array {
        $guard = self::terminal_guard( $booking_id );
        if ( empty( $guard['ok'] ) ) return ['ok'=>false,'message'=> $guard['message']];

        $lock = self::acquire_lock( $booking_id );
        if ( empty( $lock['ok'] ) ) return ['ok'=>false,'message'=>'Already locked'];
        $lock_token = (string) ( $lock['token'] ?? '' );

        try {
            $post_lock = self::post_lock_guard( $booking_id );
            if ( empty( $post_lock['ok'] ) ) return ['ok'=>false,'message'=> $post_lock['message']];

            $inv = self::assert_transition_allowed( $booking_id, 'decline' );
            if ( empty( $inv['ok'] ) ) return ['ok'=>false,'message'=> $inv['message']];

            self::stamp_engine_version( $booking_id );

            $written = add_post_meta( $booking_id, self::META_DECISION, 'declined', true );
            if ( ! $written ) return ['ok'=>false,'message'=>'Decision write failed'];

            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            $flow_mode = get_post_meta( $booking_id, '_bsbt_flow_mode', true );
            $is_manual = ($flow_mode === 'manual');

            if (!$is_manual) {
                $order = self::find_order_for_booking( $booking_id );
                if ( $order instanceof WC_Order ) {
                    try {
                        $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                        $order->save();
                        if ( ! $order->is_paid() ) $order->update_status( 'cancelled', 'BSBT: Owner declined.' );
                    } catch ( \Throwable $e ) {}
                }
            }

            self::set_mphb_status_safe( $booking_id, 'cancelled' );
            return ['ok'=>true,'message'=>'Declined'];

        } finally {
            self::release_lock( $booking_id, $lock_token );
        }
    }

    public static function process_auto_expire(): void {
        if ( ! function_exists('MPHB') ) return;

        $q = new WP_Query([
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_query'     => [['key' => self::META_DECISION, 'compare' => 'NOT EXISTS']],
            'date_query'     => [['before' => '24 hours ago']],
        ]);

        if ( ! $q->have_posts() ) return;

        foreach ( $q->posts as $booking_id ) {
            $booking_id = (int) $booking_id;
            $guard = self::terminal_guard( $booking_id );
            if ( empty( $guard['ok'] ) ) continue;

            $lock = self::acquire_lock( $booking_id );
            if ( empty( $lock['ok'] ) ) continue;
            $lock_token = (string) ( $lock['token'] ?? '' );

            try {
                $post_lock = self::post_lock_guard( $booking_id );
                if ( empty( $post_lock['ok'] ) ) continue;

                $inv = self::assert_transition_allowed( $booking_id, 'expire' );
                if ( empty( $inv['ok'] ) ) continue;

                self::stamp_engine_version( $booking_id );
                $written = add_post_meta( $booking_id, self::META_DECISION, 'expired', true );
                if ( ! $written ) continue;

                update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

                $flow_mode = get_post_meta( $booking_id, '_bsbt_flow_mode', true );
                $is_manual = ($flow_mode === 'manual');

                if (!$is_manual) {
                    $order = self::find_order_for_booking( $booking_id );
                    if ( $order instanceof WC_Order ) {
                        try {
                            $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                            $order->save();
                            if ( ! $order->is_paid() ) $order->update_status( 'cancelled', 'BSBT: Auto-expire.' );
                        } catch ( \Throwable $e ) {}
                    }
                }

                self::set_mphb_status_safe( $booking_id, 'pending' );
            } finally {
                self::release_lock( $booking_id, $lock_token );
            }
        }
    }

    private static function find_order_for_booking( int $booking_id ): ?WC_Order {
        if ( $booking_id <= 0 ) return null;
        if ( ! function_exists('wc_get_orders') ) return null;

        $statuses = array_keys( wc_get_order_statuses() );

        $orders = wc_get_orders([
            'limit' => 1, 'meta_key' => self::META_BSBT_REF, 'meta_value' => $booking_id,
            'status' => $statuses, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        if ( ! empty($orders) && $orders[0] instanceof WC_Order ) return $orders[0];

        $orders = wc_get_orders([
            'limit' => 1, 'meta_key' => '_mphb_booking_id', 'meta_value' => $booking_id,
            'status' => $statuses, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        if ( ! empty($orders) && $orders[0] instanceof WC_Order ) return $orders[0];

        $order_id = self::resolve_order_id_via_mphb_payment_bridge( $booking_id );
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order ) return $order;
        }

        return null;
    }

    private static function resolve_order_id_via_mphb_payment_bridge( int $booking_id ): int {
        if ( $booking_id <= 0 ) return 0;
        global $wpdb;

        $payments = get_posts([
            'post_type' => 'mphb_payment', 'post_status' => 'any', 'posts_per_page' => 1,
            'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC',
            'meta_query' => [['key' => '_mphb_booking_id', 'value' => (string) $booking_id, 'compare' => '=' ]],
        ]);
        $payment_id = ! empty($payments) ? (int) $payments[0] : 0;

        if ( $payment_id <= 0 ) {
            $payments = get_posts([
                'post_type' => 'mphb_payment', 'post_status' => 'any', 'posts_per_page' => 1,
                'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC',
                'meta_query' => [['key' => 'mphb_booking_id', 'value' => (string) $booking_id, 'compare' => '=' ]],
            ]);
            $payment_id = ! empty($payments) ? (int) $payments[0] : 0;
        }
        if ( $payment_id <= 0 ) return 0;

        $table_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $table_items    = $wpdb->prefix . 'woocommerce_order_items';

        $exists_itemmeta = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_itemmeta) );
        $exists_items    = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_items) );

        if ( $exists_itemmeta !== $table_itemmeta || $exists_items !== $table_items ) return 0;

        $sql = "SELECT oi.order_id FROM {$table_itemmeta} oim JOIN {$table_items} oi ON oi.order_item_id = oim.order_item_id WHERE oim.meta_key = %s AND oim.meta_value = %s ORDER BY oi.order_id DESC LIMIT 1";
        $order_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, '_mphb_payment_id', (string) $payment_id ) );

        return $order_id > 0 ? $order_id : 0;
    }

    private static function set_mphb_status_safe( int $booking_id, string $status ): void {
        if ( ! function_exists('MPHB') ) return;
        try {
            $booking = MPHB()->getBookingRepository()->findById( $booking_id );
            if ( ! $booking ) return;

            $allowed = ['pending-user', 'pending-payment', 'pending', 'abandoned', 'confirmed', 'cancelled'];
            if ( ! in_array( $status, $allowed, true ) ) return;

            $booking->setStatus( $status );
            MPHB()->getBookingRepository()->save( $booking );

            if ( class_exists('\MPHB\Shortcodes\SearchAvailabilityShortcode') ) {
                \MPHB\Shortcodes\SearchAvailabilityShortcode::clearCache();
            }
        } catch ( \Throwable $e ) {
            error_log('[BSBT MPHB ERR] ' . $e->getMessage());
        }
    }
}