<?php

declare(strict_types=1);

namespace StayFlow\OwnerPortal;

/**
 * Plugin Name: BSBT – Owner Finances
 * Description: Финансовый отчет владельца на базе Snapshot. (V1.4.3 - Tablet/Mobile UI Fixes)
 * Version: 1.4.3
 */

// ==========================================================================
// RU: Защита от прямого доступа
// EN: Prevent direct access
// ==========================================================================
if (!defined('ABSPATH')) {
    exit;
}

// ==========================================================================
// RU: Класс обработки финансов владельца
// EN: Owner finances processing class
// ==========================================================================
final class BSBT_Owner_Finances {

    public function __construct() {
        add_shortcode('bsbt_owner_finances', [$this, 'render']);
    }

    // ==========================================================================
    // RU: Проверка прав (админ или владелец)
    // EN: Check permissions (admin or owner)
    // ==========================================================================
    private function is_owner_or_admin(): bool {
        if (current_user_can('manage_options')) return true;
        $u = wp_get_current_user();
        return in_array('owner', (array)$u->roles, true);
    }

    // ==========================================================================
    // RU: Получение ID владельца бронирования
    // EN: Get booking owner ID
    // ==========================================================================
    private function get_booking_owner_id(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid) return $oid;

        if (!function_exists('MPHB')) return 0;
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return 0;

        $room = $b->getReservedRooms()[0] ?? null;
        if (!$room || !method_exists($room, 'getRoomTypeId')) return 0;

        return (int) get_post_meta($room->getRoomTypeId(), 'bsbt_owner_id', true);
    }

    // ==========================================================================
    // RU: Отрисовка шорткода
    // EN: Render shortcode
    // ==========================================================================
    public function render(): string {
        if (!is_user_logged_in() || !$this->is_owner_or_admin()) {
            return '<p>Zugriff verweigert.</p>';
        }

        try {
            $user_id  = get_current_user_id();
            $is_admin = current_user_can('manage_options');
            $selected_year = isset($_GET['f_year']) ? (int)$_GET['f_year'] : (int)date('Y');
            $paged = max(1, (int)($_GET['paged'] ?? 1));

            $args = [
                'post_type'      => 'mphb_booking',
                'post_status'    => 'any',
                'posts_per_page' => 25,
                'paged'          => $paged,
                'meta_key'       => 'mphb_check_in_date',
                'meta_type'      => 'DATE',
                'orderby'        => 'meta_value',
                'order'          => 'DESC',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_bsbt_snapshot_owner_payout', 'compare' => 'EXISTS']
                ]
            ];

            $query = new \WP_Query($args);

            $total_sum       = 0.0;
            $has_rows        = false;
            $rows_html       = '';
            $available_years = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $bid = get_the_ID();

                    if (!$is_admin && $this->get_booking_owner_id($bid) !== $user_id) continue;

                    $in = (string)get_post_meta($bid, 'mphb_check_in_date', true);
                    if (!$in) continue;

                    $year = (int)date('Y', strtotime($in));
                    if ($year > 0) $available_years[$year] = $year;

                    if ($year !== $selected_year) continue;

                    $out      = (string)get_post_meta($bid, 'mphb_check_out_date', true);
                    $payout   = (float) get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
                    $payout_display = $payout;

                    $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
                    if ($decision === '') $decision = '—';

                    // ==========================================
                    // RU: Исключение бесплатных отмен
                    // EN: Exclude free cancellations
                    // ==========================================
                    $snapshot = get_post_meta($bid, '_bsbt_financial_snapshot', true);
                    $post_status = get_post_status($bid);
                    
                    $is_cancelled = ($decision === 'cancelled' || $post_status === 'cancelled' || (is_array($snapshot) && isset($snapshot['status']) && $snapshot['status'] === 'cancelled'));

                    if ($is_cancelled) {
                        $refund_type = is_array($snapshot) ? ($snapshot['refund_type'] ?? '') : '';
                        if ($refund_type === '100%') {
                            continue; // Пропускаем отмены без штрафа
                        }
                    }

                    $has_rows = true;
                    if ($payout > 0) {
                        $total_sum += $payout;
                    }

                    $apt_name = '—';
                    if (function_exists('MPHB')) {
                        $b = MPHB()->getBookingRepository()->findById($bid);
                        if ($b && !empty($rooms = $b->getReservedRooms())) {
                            $apt_name = get_the_title($rooms[0]->getRoomTypeId()) ?: '—';
                        }
                    }

                    $pdf_nonce = wp_create_nonce('bsbt_owner_pdf_' . $bid);
                    $pdf_url   = admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$pdf_nonce");

                    ob_start(); ?>
                    <tr>
                        <td class="col-booking">
                            <span class="mobile-label">Booking:</span>
                            <div class="cell-content">
                                <strong>#<?= (int)$bid ?></strong>
                                <small><?= esc_html($apt_name) ?></small>
                                <small style="margin-top:2px;">Decision: <?= esc_html($decision) ?></small>
                            </div>
                        </td>
                        <td class="col-stay">
                            <span class="mobile-label">Zeitraum:</span>
                            <div class="cell-content"><?= esc_html($in) ?> – <?= esc_html($out) ?></div>
                        </td>
                        <td class="col-payout">
                            <span class="mobile-label">Auszahlung:</span>
                            <div class="cell-content">
                                <strong><?= number_format((float)$payout_display, 2, ',', '.') ?> €</strong>
                            </div>
                        </td>
                        <td class="col-pdf">
                            <a href="<?= esc_url($pdf_url) ?>" target="_blank" class="bsbt-pdf-btn-v3">
                                <span class="btn-text">📄 PDF Öffnen</span>
                            </a>
                        </td>
                    </tr>
                    <?php $rows_html .= ob_get_clean();
                }
                wp_reset_postdata();
            }

            rsort($available_years);

            ob_start(); ?>
            <style>
                .bsbt-finances-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
                .bsbt-year-tabs { display:flex; gap:8px; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px; flex-wrap: wrap; }
                .bsbt-year-tabs a { padding:8px 16px; text-decoration:none; border-radius:4px; font-weight:600; font-size:13px; transition: all 0.2s; }
                .bsbt-year-tabs a.active { background:#082567; color:#fff; }
                .bsbt-year-tabs a.inactive { background:#f4f4f4; color:#555; border:1px solid #eee; }
                
                /* RU: Добавлен overflow-x: auto для планшетов */
                .bsbt-card { background:#fff; border:1px solid #e5e5e5; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.05); overflow-x:auto; margin-bottom: 20px; }
                
                .bsbt-table { width:100%; border-collapse: collapse; min-width: 600px; }
                .bsbt-table th { background:#f8fafc; text-align:left; color:#082567; font-size:11px; text-transform:uppercase; font-weight:800; letter-spacing:0.5px; padding:15px 12px; border-bottom:1px solid #e2e8f0; }
                .bsbt-table td { padding:18px 12px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align: middle; }
                .cell-content { font-size:14px; line-height:1.4; }
                .cell-content small { color:#94a3b8; display:block; font-size:11px; font-weight: 600; }
                
                .bsbt-pdf-btn-v3 { position: relative !important; overflow: hidden !important; display: inline-flex !important; align-items: center; justify-content: center; padding: 10px 20px !important; border-radius: 10px !important; border: none !important; text-decoration: none !important; font-size: 13px !important; font-weight: 700 !important; cursor: pointer !important; z-index: 2; transition: all 0.25s ease !important; background-color: #082567 !important; color: #E0B849 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; }
                .bsbt-pdf-btn-v3::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; pointer-events: none !important; }
                .bsbt-pdf-btn-v3:hover { background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.1) 100%) !important; transform: translateY(-2px) !important; }
                .btn-csv { background-color: #10b981 !important; color: #fff !important; }
                .btn-csv:hover { background-color: #059669 !important; color: #fff !important; }
                
                .mobile-label { display:none; font-weight:800; color:#082567; font-size:10px; text-transform:uppercase; margin-bottom:4px; opacity: 0.6; }
                .bsbt-table tfoot td { background:#f8fafc; padding:25px 12px; font-size:16px; border-top: 2px solid #e2e8f0; }
                .total-label { font-weight:800; color:#082567; }
                .total-amount { font-weight:900; color:#082567; font-size:24px; }
                
                /* ==========================================================================
                   RU: Адаптивность для мобильных и планшетов
                   EN: Mobile and Tablet Responsiveness
                   ========================================================================== */
                @media (max-width: 768px) {
                    .bsbt-table { min-width: unset; }
                    .bsbt-table thead { display:none; }
                    .bsbt-table, .bsbt-table tbody, .bsbt-table tr, .bsbt-table td { display:block; width:100%; box-sizing: border-box; }
                    .bsbt-table tr { margin-bottom:20px; border:1px solid #e2e8f0; border-radius:12px; padding: 5px 0; background: #fff; }
                    .bsbt-table td { text-align:right; padding:12px 15px; position:relative; border-bottom:1px solid #f1f5f9; }
                    .bsbt-table td:last-child { border-bottom:none; }
                    
                    .mobile-label { display:block; float:left; line-height: 20px; }
                    .cell-content { display:inline-block; max-width:65%; text-align:right; }
                    .col-pdf { text-align: center !important; padding: 15px !important; background: #fcfcfc; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; }
                    .bsbt-pdf-btn-v3 { display: flex; width: 100%; padding: 14px !important; font-size: 15px !important; }
                    
                    .action-buttons { flex-direction: column; width: 100%; }
                    .action-buttons button { width: 100%; margin-bottom: 5px; }
                    
                    /* RU: Фикс строки итогов для мобильных */
                    /* EN: Mobile summary row fix */
                    .bsbt-table tfoot { display: block; margin-top: 15px; }
                    .bsbt-table tfoot tr { display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 0; }
                    .bsbt-table tfoot td { display: block; width: auto; padding: 0; border: none; background: transparent; }
                    .total-label { text-align: left !important; font-size: 14px !important; }
                    .total-amount { text-align: right !important; font-size: 20px !important; }
                }
            </style>

            <div class="bsbt-finances-wrap">
                <div style="background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #082567; font-size: 18px;">📊 Monatsabrechnung</h3>
                        <p style="margin: 0; font-size: 13px; color: #64748b;">Laden Sie eine detaillierte Übersicht herunter (basierend auf dem Abreisedatum).</p>
                    </div>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="bsbt_owner_monthly_report">
                        <?php wp_nonce_field('bsbt_owner_monthly_report', 'monthly_report_nonce'); ?>
                        
                        <select name="f_month" style="padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-weight: 600; color: #082567; background: #f8fafc; font-size: 14px;">
                            <?php
                            $months = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
                            $current_m = (int)date('n');
                            foreach($months as $m_num => $m_name) {
                                $sel = ($m_num === $current_m) ? 'selected' : '';
                                echo "<option value='{$m_num}' {$sel}>{$m_name}</option>";
                            }
                            ?>
                        </select>
                        
                        <select name="f_year" style="padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-weight: 600; color: #082567; background: #f8fafc; font-size: 14px;">
                            <?php
                            if (!empty($available_years)) {
                                foreach ($available_years as $y) {
                                    $sel = ($y === $selected_year) ? 'selected' : '';
                                    echo "<option value='{$y}' {$sel}>{$y}</option>";
                                }
                            } else {
                                echo "<option value='{$selected_year}'>{$selected_year}</option>";
                            }
                            ?>
                        </select>
                        
                        <div class="action-buttons" style="display:flex; gap:10px;">
                            <button type="submit" name="format" value="pdf" class="bsbt-pdf-btn-v3">📄 PDF</button>
                            <button type="submit" name="format" value="csv" class="bsbt-pdf-btn-v3 btn-csv">📊 CSV (Excel)</button>
                        </div>
                    </form>
                </div>

                <?php if (count($available_years) > 1): ?>
                <div class="bsbt-year-tabs">
                    <?php foreach ($available_years as $y):
                        $active = ($y === $selected_year); ?>
                        <a href="<?= esc_url(add_query_arg('f_year', $y)) ?>" class="<?= $active ? 'active' : 'inactive' ?>">
                            <?= (int)$y ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="bsbt-card">
                    <table class="bsbt-table">
                        <thead>
                            <tr>
                                <th class="col-booking">Booking / Apt</th>
                                <th class="col-stay">Zeitraum</th>
                                <th class="col-payout">Auszahlung</th>
                                <th class="col-pdf" style="text-align:center;">Beleg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($has_rows):
                                echo $rows_html;
                            else: ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding:50px 20px; color:#94a3b8;">
                                        <div style="font-size:32px; margin-bottom:10px;">📊</div>
                                        Keine Auszahlungen gefunden.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($total_sum > 0): ?>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="total-label" style="text-align:right;">Gesamt <?= (int)$selected_year ?>:</td>
                                <td colspan="2" class="total-amount"><?= number_format((float)$total_sum, 2, ',', '.') ?> €</td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if ( $query->max_num_pages > 1 ): ?>
                    <div style="margin-top:18px; text-align:right;">
                        <?php
                        echo paginate_links([
                            'total'   => $query->max_num_pages,
                            'current' => $paged,
                            'format'  => '?paged=%#%',
                            'add_args' => [
                                'f_year' => $selected_year
                            ],
                            'prev_text' => '«',
                            'next_text' => '»',
                        ]);
                        ?>
                    </div>
                <?php endif; ?>

            </div>
            <?php
            return ob_get_clean();

        } catch (\Throwable $e) {
            // RU: Гашение фатальных ошибок
            // EN: Suppress fatal errors
            error_log('StayFlow BSBT_Owner_Finances Error: ' . $e->getMessage());
            return '<div style="padding: 20px; background: #fee2e2; color: #991b1b; border-radius: 8px;">Ein Fehler ist aufgetreten beim Laden der Finanzen. Bitte laden Sie die Seite neu oder kontaktieren Sie den Support.</div>';
        }
    }
}

new BSBT_Owner_Finances();