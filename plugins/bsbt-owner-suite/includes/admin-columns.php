<?php
/**
 * UI Columns for Owner Portal
 * Version: 11.7.0 - Clean UI, Robust Footer Totals (Price + Payout + VAT)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 * UTILS
 * RU: Вспомогательные функции для расчета дат.
 * EN: Helper functions for dates calculation.
 * ========================================================= */
function bsbt__get_dates_raw( $booking_id ) {
    $in  = get_post_meta( $booking_id, 'mphb_check_in_date', true ) ?: get_post_meta( $booking_id, '_mphb_check_in_date', true );
    $out = get_post_meta( $booking_id, 'mphb_check_out_date', true ) ?: get_post_meta( $booking_id, '_mphb_check_out_date', true );
    return [$in, $out];
}

function bsbt__nights( $in, $out ) {
    $ti = strtotime( $in );
    $to = strtotime( $out );
    if ( ! $ti || ! $to ) return 0;
    return (int) round( max( 0, $to - $ti ) / DAY_IN_SECONDS );
}

/* =========================================================
 * 1. ADD COLUMNS (INSERT AFTER 'PRICE')
 * RU: Добавляем колонки после "Price".
 * EN: Inject custom columns after "Price".
 * ========================================================= */
add_filter( 'manage_edit-mphb_booking_columns', 'bsbt_add_fin_columns', 999 );
function bsbt_add_fin_columns( $columns ) {
    $new_columns = [];
    foreach ( $columns as $key => $value ) {
        $new_columns[$key] = $value;
        if ( $key === 'price' ) {
            $new_columns['bsbt_model']          = 'Model';
            $new_columns['bsbt_purchase_total'] = 'Einkauf (Payout)';
            $new_columns['bsbt_vat']            = 'MwSt (VAT)';
        }
    }
    return $new_columns;
}

/* =========================================================
 * 2. RENDER COLUMNS
 * RU: Отрисовка данных с fallback-логикой (без жирного и красного).
 * EN: Render column data with fallback logic (no bold, no red).
 * ========================================================= */
add_action( 'manage_mphb_booking_posts_custom_column', 'bsbt_render_fin_columns', 999, 2 );
function bsbt_render_fin_columns( $col, $post_id ) {
    
    $allowed_cols = ['bsbt_model', 'bsbt_purchase_total', 'bsbt_vat'];
    if ( ! in_array( $col, $allowed_cols, true ) ) return;

    try {
        $booking = function_exists('MPHB') ? MPHB()->getBookingRepository()->findById( $post_id ) : null;
        
        $rt_id = 0;
        if ( $booking && method_exists( $booking, 'getReservedRooms' ) ) {
            $rooms = $booking->getReservedRooms();
            if ( ! empty( $rooms ) ) {
                foreach ( $rooms as $room ) {
                    if ( is_object($room) && method_exists($room, 'getRoomTypeId') ) {
                        $rt_id = (int) $room->getRoomTypeId();
                        break;
                    }
                }
            }
        }

        list($in, $out) = bsbt__get_dates_raw( $post_id );
        $nights = max( 1, bsbt__nights( $in, $out ) );

        $is_snapshot = (bool) get_post_meta( $post_id, '_bsbt_snapshot_locked_at', true );
        $snap_model  = get_post_meta( $post_id, '_bsbt_snapshot_model', true );
        $model       = $snap_model ? $snap_model : ( get_post_meta( $rt_id, '_bsbt_business_model', true ) ?: 'model_a' );

        $payout      = 0.0;
        $guest_total = 0.0;
        $vat         = 0.0;
        $vat_label   = '';

        /* --- SMART GUEST TOTAL EXTRACTION --- */
        if ( $is_snapshot ) {
            $guest_total = (float) get_post_meta( $post_id, '_bsbt_snapshot_guest_total', true );
        } else {
            if ( $booking && method_exists( $booking, 'getTotalPrice' ) ) {
                $guest_total = (float) $booking->getTotalPrice();
            }
            if ( $guest_total <= 0 ) $guest_total = (float) get_post_meta( $post_id, '_mphb_total_price', true );
            if ( $guest_total <= 0 ) $guest_total = (float) get_post_meta( $post_id, 'mphb_booking_total_price', true );
        }

        /* --- CALCULATION LOGIC --- */
        if ( $is_snapshot ) {
            $payout = (float) get_post_meta( $post_id, '_bsbt_snapshot_owner_payout', true );

            if ( $model === 'model_b' ) {
                $vat = (float) get_post_meta( $post_id, '_bsbt_snapshot_fee_vat_total', true );
                $vat_label = '19% (Provision)';
            } else {
                $vat = $guest_total - ( $guest_total / 1.07 );
                $vat_label = '7% (Gesamt)';
            }
        } else {
            if ( $model === 'model_b' ) {
                $fee_rate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15;
                $vat_rate = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.19;
                
                $fee    = $guest_total * $fee_rate;
                $vat    = $fee * $vat_rate;
                $payout = $guest_total - $fee;
                $vat_label = '19% (Provision)';
            } else {
                $ppn = (float) get_post_meta( $rt_id, 'owner_price_per_night', true );
                if ( ! $ppn && function_exists('get_field') ) {
                    $ppn = (float) get_field( 'owner_price_per_night', $rt_id );
                }
                $payout = $ppn * $nights;
                $vat = $guest_total - ( $guest_total / 1.07 );
                $vat_label = '7% (Gesamt)';
            }
        }

        $prefix = $is_snapshot ? '' : '<span title="Pending / Geschätzt" style="color:#999;">~ </span>';

        /* --- RENDER COLUMN OUTPUT --- */
        if ( $col === 'bsbt_model' ) {
            $model_text = ( $model === 'model_b' ) ? 'MODEL B' : 'MODEL A';
            // Скрытый span для передачи суммы Price в скрипт итогов (чтобы избежать ошибок парсинга валют)
            echo '<span class="bsbt-sum bsbt-guest-hidden" data-val="'.esc_attr($guest_total).'" style="display:none;"></span>';
            echo '<span style="display:inline-block; padding:3px 6px; background:#f0f0f1; border-radius:4px; font-size:11px; font-weight:bold; color:#212f54; border:1px solid #dcdcdd;">' . esc_html( $model_text ) . '</span>';
        }

        if ( $col === 'bsbt_purchase_total' ) {
            // RU: Убран жирный шрифт и синий цвет / EN: Removed bold and custom color
            echo '<span class="bsbt-sum bsbt-purchase" data-val="'.esc_attr($payout).'" style="font-size:13px; color:#3c434a;">';
            echo $prefix . esc_html( number_format( $payout, 2, ',', '.' ) ) . ' €</span>';
        }

        if ( $col === 'bsbt_vat' ) {
            // RU: Убран красный цвет / EN: Removed red color
            echo '<span class="bsbt-sum bsbt-vat" data-val="'.esc_attr($vat).'" style="font-size:13px; color:#3c434a;">';
            echo $prefix . esc_html( number_format( $vat, 2, ',', '.' ) ) . ' €</span><br>';
            echo '<small style="color:#777; font-size:10px;">' . esc_html( $vat_label ) . '</small>';
        }

    } catch ( \Throwable $e ) {
        echo '<span style="color:#dc3232; font-size:10px;" title="' . esc_attr($e->getMessage()) . '">Error</span>';
    }
}

/* =========================================================
 * 3. FOOTER TOTALS (JavaScript)
 * RU: JS скрипт суммирования колонок. Привязан жестко к экрану.
 * EN: JS script to calculate totals. Hard-bound to the screen.
 * ========================================================= */
add_action( 'admin_footer', 'bsbt_fin_totals_footer' );
function bsbt_fin_totals_footer() {
    global $pagenow;
    // RU: Выполняем строго на странице списка бронирований
    if ( $pagenow !== 'edit.php' || ! isset($_GET['post_type']) || $_GET['post_type'] !== 'mphb_booking' ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.querySelector('table.wp-list-table.posts');
        if (!table) return;

        let p = 0, v = 0, priceTotal = 0;
        
        // Sum Payout
        table.querySelectorAll('span.bsbt-purchase').forEach(el => p += parseFloat(el.getAttribute('data-val')) || 0);
        // Sum VAT
        table.querySelectorAll('span.bsbt-vat').forEach(el => v += parseFloat(el.getAttribute('data-val')) || 0);
        // Sum Guest Price (Hidden attribute)
        table.querySelectorAll('span.bsbt-guest-hidden').forEach(el => priceTotal += parseFloat(el.getAttribute('data-val')) || 0);

        const tfoot = table.querySelector('tfoot');
        const thead = table.querySelector('thead tr');
        
        if (thead && tfoot) {
            // Проверка, чтобы не добавить строку дважды (бывает при аяксе)
            if (tfoot.querySelector('.bsbt-totals-row')) return;

            const tr = document.createElement('tr');
            tr.className = 'bsbt-totals-row';
            tr.style.backgroundColor = '#f6f7f7'; 

            const fmt = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            Array.from(thead.children).forEach(th => {
                const td = document.createElement('td');
                td.style.fontWeight = 'bold';
                td.style.padding = '12px 8px';
                td.style.fontSize = '13px';
                td.style.color = '#1d2327'; // Стандартный темный цвет WP

                if (th.id === 'price') {
                    td.innerHTML = '∑ ' + fmt.format(priceTotal) + ' €';
                } else if (th.id === 'bsbt_purchase_total') {
                    td.innerHTML = '∑ ' + fmt.format(p) + ' €';
                } else if (th.id === 'bsbt_vat') {
                    td.innerHTML = '∑ ' + fmt.format(v) + ' €';
                } else if (th.id === 'customer_info') {
                    td.innerHTML = 'Summe (Seite):';
                    td.style.textAlign = 'right';
                    td.style.color = '#50575e';
                } else {
                    td.innerHTML = ''; 
                }

                tr.appendChild(td);
            });

            tfoot.prepend(tr);
        }
    });
    </script>
    <?php
}