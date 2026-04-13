<?php

declare(strict_types=1);

namespace StayFlow\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 4.0.0
 *
 * RU:
 * Центр Финансов.
 * - Исправлен баг поиска по датам (MPHB сохраняет даты без нижнего подчеркивания).
 * - Security Fix: строгий Access Control для защиты финансовых данных.
 * - Hardening v4.0.0:
 *   - Whitelist для активной вкладки.
 *   - Дополнительное экранирование вывода.
 *   - Защита CSV Export от formula injection.
 *
 * EN:
 * Finance Hub.
 * - Fixed date search bug (MPHB stores dates without underscore).
 * - Security Fix: strict Access Control to protect financial data.
 * - Hardening v4.0.0:
 *   - Tab whitelist.
 *   - Additional output escaping.
 *   - CSV export protection against formula injection.
 */
final class FinanceHub
{
    /**
     * RU: Строгая проверка даты для исключения мусорных значений.
     * EN: Strict date check to exclude garbage values.
     */
    private function isValidDate(string $date): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    /**
     * RU: Разрешенные вкладки Finance Hub.
     */
    private function getAllowedTabs(): array
    {
        return ['payouts', 'citytax', 'dac7'];
    }

    private function sanitizeTab(string $tab): string
    {
        $tab = sanitize_key($tab);
        return in_array($tab, $this->getAllowedTabs(), true) ? $tab : 'payouts';
    }

    public function render(): void
    {
        // ==========================================
        // RU: ЖЕСТКИЙ КОНТРОЛЬ ДОСТУПА (Access Control)
        // EN: STRICT ACCESS CONTROL
        // ==========================================
        if (!current_user_can('manage_options')) {
            wp_die(
                'Zugriff verweigert. Sie haben keine Berechtigung, diese Seite aufzurufen.',
                'Stay4Fair Security',
                ['response' => 403]
            );
        }

        $tab = isset($_GET['sftab']) ? $this->sanitizeTab((string) $_GET['sftab']) : 'payouts';

        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title sf-no-print" style="color:#000; font-weight:900; margin-bottom:20px;">
                Finance <span style="background:#ff9000; color:#000; padding:4px 10px; border-radius:6px;">Hub</span>
            </h1>

            <h2 class="nav-tab-wrapper sf-no-print" style="margin-bottom:20px; border-bottom:2px solid #e2e8f0;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=stayflow-finance&sftab=payouts')); ?>"
                   class="nav-tab <?php echo $tab === 'payouts' ? 'nav-tab-active' : ''; ?>"
                   style="<?php echo $tab === 'payouts' ? 'border-bottom:2px solid #ff9000; background:#000; color:#ff9000;' : ''; ?>">
                    💳 Payouts (A/B)
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=stayflow-finance&sftab=citytax')); ?>"
                   class="nav-tab <?php echo $tab === 'citytax' ? 'nav-tab-active' : ''; ?>"
                   style="<?php echo $tab === 'citytax' ? 'border-bottom:2px solid #ff9000; background:#000; color:#ff9000;' : ''; ?>">
                    🏛 Beherbergungsteuer
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=stayflow-finance&sftab=dac7')); ?>"
                   class="nav-tab <?php echo $tab === 'dac7' ? 'nav-tab-active' : ''; ?>"
                   style="<?php echo $tab === 'dac7' ? 'border-bottom:2px solid #ff9000; background:#000; color:#ff9000;' : ''; ?>">
                    📁 DAC7 Export
                </a>
            </h2>

            <div class="sf-finance-content" id="sf-print-area">
                <?php
                if ($tab === 'payouts') {
                    $this->renderPayoutsTable();
                } elseif ($tab === 'citytax') {
                    $this->renderCityTaxTable();
                } elseif ($tab === 'dac7') {
                    $this->renderDac7Table();
                }
                ?>
            </div>
        </div>
        <?php

        $this->renderStyles();
        $this->renderExportScripts();
    }

    /**
     * ==========================================
     * SECTION 1: PAYOUTS
     * ==========================================
     */
    private function renderPayoutsTable(): void
    {
        $filter_from  = !empty($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : date('Y-m-01');
        $filter_to    = !empty($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : date('Y-m-t');
        $filter_model = isset($_GET['f_model']) ? sanitize_text_field((string) $_GET['f_model']) : 'all';

        if (!$this->isValidDate($filter_from) || !$this->isValidDate($filter_to)) {
            $filter_from = date('Y-m-01');
            $filter_to   = date('Y-m-t');
        }

        $d_from = new \DateTime($filter_from);
        $d_to   = new \DateTime($filter_to);

        if ($d_from > $d_to) {
            $filter_to = $filter_from;
            $d_to = clone $d_from;
        }

        if ($d_from->diff($d_to)->days > 365) {
            $d_to = clone $d_from;
            $d_to->modify('+365 days');
            $filter_to = $d_to->format('Y-m-d');
        }

        $filter_model = in_array($filter_model, ['all', 'A', 'B'], true) ? $filter_model : 'all';

        // RU: Исправленный meta_query. Ищет и с подчеркиванием, и без него.
        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => ['confirmed', 'completed', 'cancelled'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'mphb_check_in_date',
                    'value'   => [$filter_from, $filter_to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => '_mphb_check_in_date',
                    'value'   => [$filter_from, $filter_to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ];

        $bookings = get_posts($args);
        $payout_data = [];
        $totals = [
            'gross'     => 0.0,
            'host'      => 0.0,
            'our_gross' => 0.0,
            'vat'       => 0.0,
            'citytax'   => 0.0,
            'our_net'   => 0.0,
        ];

        foreach ($bookings as $b) {
            $b_id   = (int) $b->ID;
            $status = (string) get_post_status($b_id);

            if ($status === 'cancelled') {
                $snapshot = get_post_meta($b_id, '_bsbt_financial_snapshot', true);
                $refund_type = is_array($snapshot) ? (string) ($snapshot['refund_type'] ?? '') : '';
                if ($refund_type === '100%') {
                    continue;
                }
            }

            $edit_url = admin_url("post.php?post={$b_id}&action=edit");
            $ext_id   = get_post_meta($b_id, '_mphb_sync_id', true) ?: get_post_meta($b_id, 'external_booking_id', true) ?: '—';

            $model_raw = (string) get_post_meta($b_id, '_bsbt_snapshot_model', true);
            if ($model_raw === '') {
                continue;
            }

            $model = strtoupper(str_replace('model_', '', $model_raw));
            if ($filter_model !== 'all' && $model !== strtoupper($filter_model)) {
                continue;
            }

            $gross      = (float) get_post_meta($b_id, '_bsbt_snapshot_guest_total', true);
            $hostPayout = (float) get_post_meta($b_id, '_bsbt_snapshot_owner_payout', true);

            $room_id  = (int) get_post_meta($b_id, '_bsbt_snapshot_room_type_id', true);
            $owner_id = (int) get_post_meta($b_id, '_bsbt_snapshot_manager_user_id', true);

            $owner_name = 'Unbekannt';
            $iban = 'Fehlt';
            $iban_badge = '';

            $room_iban    = $room_id ? (get_post_meta($room_id, 'kontonummer', true) ?: get_post_meta($room_id, 'bsbt_iban', true)) : '';
            $room_inhaber = $room_id ? get_post_meta($room_id, 'kontoinhaber', true) : '';

            if (!empty($room_iban)) {
                $iban = (string) $room_iban;
                $owner_name = !empty($room_inhaber) ? (string) $room_inhaber : get_the_title($room_id);
                $iban_badge = '<span class="sf-no-print" style="font-size:9px; background:#fef9c3; color:#854d0e; padding:1px 4px; border-radius:3px; margin-left:5px;">Zimmer</span>';
            } else {
                if ($owner_id) {
                    $owner_obj = get_userdata($owner_id);
                    if ($owner_obj) {
                        $owner_name = (string) $owner_obj->display_name;
                    }

                    $user_iban = get_user_meta($owner_id, 'bsbt_iban', true) ?: get_user_meta($owner_id, 'kontonummer', true);
                    if (!empty($user_iban)) {
                        $iban = (string) $user_iban;
                        $iban_badge = '<span class="sf-no-print" style="font-size:9px; background:#dcfce7; color:#166534; padding:1px 4px; border-radius:3px; margin-left:5px;">Global</span>';
                    }
                }
            }

            $ourGross = 0.0;
            $vat      = 0.0;
            $city_tax = 0.0;
            $ourNet   = 0.0;

            if ($model === 'A') {
                $ourGross = $gross;
                $vat = $gross - ($gross / 1.07);

                $nights = (int) get_post_meta($b_id, '_bsbt_snapshot_nights', true);
                if ($nights <= 0) {
                    $nights = 1;
                }

                $adults = 0;
                if (function_exists('MPHB')) {
                    $booking_obj = \MPHB()->getBookingRepository()->findById($b_id);
                    if ($booking_obj && method_exists($booking_obj, 'getReservedRooms')) {
                        foreach ($booking_obj->getReservedRooms() as $res_room) {
                            if (method_exists($res_room, 'getAdults')) {
                                $adults += (int) $res_room->getAdults();
                            }
                        }
                    }
                }

                if ($adults <= 0) {
                    $adults = (int) get_post_meta($b_id, 'mphb_adults', true);
                }
                if ($adults <= 0) {
                    $adults = 1;
                }

                $price_per_person = $gross / ($adults * $nights);
                $tax_rate = 0.0;

                if ($price_per_person <= 10.00) {
                    $tax_rate = 0.50;
                } elseif ($price_per_person <= 25.00) {
                    $tax_rate = 1.50;
                } elseif ($price_per_person <= 50.00) {
                    $tax_rate = 3.00;
                } elseif ($price_per_person <= 100.00) {
                    $tax_rate = 4.00;
                } elseif ($price_per_person <= 150.00) {
                    $tax_rate = 5.00;
                } else {
                    $extra = (int) ceil(($price_per_person - 150) / 50);
                    $tax_rate = 5.00 + ($extra * 1.00);
                }

                $date_in = get_post_meta($b_id, 'mphb_check_in_date', true);
                $year = $date_in ? (int) date('Y', strtotime((string) $date_in)) : 0;

                if ($year >= 2026 && $price_per_person > 450.00) {
                    $tax_rate = 12.00;
                }

                if ($status === 'cancelled') {
                    $city_tax = 0.0;
                } else {
                    $city_tax = $tax_rate * $adults * $nights;
                }

                $ourNet = $gross - $vat - $hostPayout - $city_tax;
            } else {
                $ourGross = (float) get_post_meta($b_id, '_bsbt_snapshot_fee_gross_total', true);
                $vat      = (float) get_post_meta($b_id, '_bsbt_snapshot_fee_vat_total', true);
                $ourNet   = (float) get_post_meta($b_id, '_bsbt_snapshot_fee_net_total', true);
            }

            $totals['gross']     += $gross;
            $totals['host']      += $hostPayout;
            $totals['our_gross'] += $ourGross;
            $totals['vat']       += $vat;
            $totals['citytax']   += $city_tax;
            $totals['our_net']   += $ourNet;

            $payout_data[] = [
                'id'          => $b_id,
                'edit_url'    => $edit_url,
                'ext_id'      => (string) $ext_id,
                'status'      => $status,
                'host'        => (string) $owner_name,
                'iban'        => (string) $iban,
                'iban_badge'  => $iban_badge, // controlled internal HTML
                'model'       => $model,
                'gross'       => $gross,
                'host_payout' => $hostPayout,
                'our_gross'   => $ourGross,
                'vat'         => $vat,
                'citytax'     => $city_tax,
                'our_net'     => $ourNet,
            ];
        }

        ?>
        <div style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
            <div class="sf-no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e2e8f0;">
                <form method="get" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="stayflow-finance">
                    <input type="hidden" name="sftab" value="payouts">
                    <label><strong>Von:</strong></label><input type="date" id="sf_date_from" name="date_from" value="<?php echo esc_attr($filter_from); ?>" style="border-radius:6px;" required>
                    <label><strong>Bis:</strong></label><input type="date" id="sf_date_to" name="date_to" value="<?php echo esc_attr($filter_to); ?>" style="border-radius:6px;" required>
                    <select name="f_model" style="border-radius:6px;">
                        <option value="all" <?php selected($filter_model, 'all'); ?>>Alle Modelle</option>
                        <option value="A" <?php selected($filter_model, 'A'); ?>>Modell A</option>
                        <option value="B" <?php selected($filter_model, 'B'); ?>>Modell B</option>
                    </select>
                    <button type="submit" class="button button-primary" style="background:#082567;">Filtern</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=stayflow-finance&sftab=payouts')); ?>" class="button">Reset</a>
                </form>

                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="window.print()" class="button" style="border-color:#ff9000; color:#000;">🖨 Drucken</button>
                    <button type="button" onclick="exportPayoutsCSV()" class="button button-primary" style="background:#10b981; border:none;">📗 CSV Export</button>
                </div>
            </div>

            <div class="sf-print-only" style="display:none; margin-bottom:20px;">
                <h2 style="margin:0;">StayFlow Auszahlungsbericht</h2>
                <p>Zeitraum: <?php echo esc_html($filter_from); ?> bis <?php echo esc_html($filter_to); ?> | Modell: <?php echo esc_html($filter_model); ?></p>
            </div>

            <table class="wp-list-table widefat fixed striped" id="sf-payouts-table">
                <thead>
                    <tr style="background:#000;">
                        <th style="color:#ff9000; width:10%;">Buchungs-ID</th>
                        <th style="color:#ff9000;">Inhaber &amp; Bank</th>
                        <th style="color:#ff9000; width:6%;">Modell</th>
                        <th style="color:#ff9000;">Brutto (Gast)</th>
                        <th style="color:#ff9000;">Auszahlung Inhaber</th>
                        <th style="color:#ff9000;">Unsere Prov.</th>
                        <th style="color:#ff9000;">USt. (VAT)</th>
                        <th style="color:#ff9000;">City Tax</th>
                        <th style="color:#ff9000;">Unser Netto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payout_data)): ?>
                        <tr><td colspan="9" style="text-align:center; padding:20px;">Keine Buchungen gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payout_data as $b): ?>
                            <tr style="<?php echo $b['status'] === 'cancelled' ? 'background:#fff5f5;' : ''; ?>">
                                <td>
                                    <strong><a href="<?php echo esc_url($b['edit_url']); ?>">#<?php echo (int) $b['id']; ?></a></strong><br>
                                    <?php if ($b['status'] === 'cancelled'): ?>
                                        <span style="font-size:9px; background:#ef4444; color:#fff; padding:1px 4px; border-radius:3px;">STORNO</span><br>
                                    <?php else: ?>
                                        <small style="color:#94a3b8;">Ext: <?php echo esc_html($b['ext_id']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($b['host']); ?></strong><br>
                                    <div style="display:flex; align-items:center; margin-top:2px;">
                                        <small style="color:#64748b; font-family:monospace;"><?php echo esc_html($b['iban']); ?></small>
                                        <?php echo $b['iban_badge']; ?>
                                    </div>
                                </td>
                                <td><span class="sf-tag-<?php echo esc_attr(strtolower($b['model'])); ?>">Modell <?php echo esc_html($b['model']); ?></span></td>
                                <td>€<?php echo esc_html(number_format((float) $b['gross'], 2, ',', '.')); ?></td>
                                <td style="color:#1e7e34; font-weight:bold;">€<?php echo esc_html(number_format((float) $b['host_payout'], 2, ',', '.')); ?></td>
                                <td>€<?php echo esc_html(number_format((float) $b['our_gross'], 2, ',', '.')); ?></td>
                                <td style="color:#d63638;">€<?php echo esc_html(number_format((float) $b['vat'], 2, ',', '.')); ?></td>
                                <td style="color:#d63638;"><?php echo $b['model'] === 'A' ? '€' . esc_html(number_format((float) $b['citytax'], 2, ',', '.')) : '—'; ?></td>
                                <td style="color:#082567; font-weight:900;">€<?php echo esc_html(number_format((float) $b['our_net'], 2, ',', '.')); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr style="background:#f8fafc; font-weight:bold; border-top:2px solid #000;">
                            <td colspan="3" style="text-align:right;">GESAMT:</td>
                            <td>€<?php echo esc_html(number_format($totals['gross'], 2, ',', '.')); ?></td>
                            <td style="color:#1e7e34;">€<?php echo esc_html(number_format($totals['host'], 2, ',', '.')); ?></td>
                            <td>€<?php echo esc_html(number_format($totals['our_gross'], 2, ',', '.')); ?></td>
                            <td style="color:#d63638;">€<?php echo esc_html(number_format($totals['vat'], 2, ',', '.')); ?></td>
                            <td style="color:#d63638;">€<?php echo esc_html(number_format($totals['citytax'], 2, ',', '.')); ?></td>
                            <td style="color:#082567; font-size:16px;">€<?php echo esc_html(number_format($totals['our_net'], 2, ',', '.')); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * ==========================================
     * SECTION 2: CITY TAX
     * ==========================================
     */
    private function renderCityTaxTable(): void
    {
        $filter_city = isset($_GET['f_city']) ? sanitize_text_field((string) $_GET['f_city']) : 'all';
        $filter_from = !empty($_GET['tax_from']) ? sanitize_text_field((string) $_GET['tax_from']) : date('Y-m-01');
        $filter_to   = !empty($_GET['tax_to']) ? sanitize_text_field((string) $_GET['tax_to']) : date('Y-m-t');

        if (!$this->isValidDate($filter_from) || !$this->isValidDate($filter_to)) {
            $filter_from = date('Y-m-01');
            $filter_to   = date('Y-m-t');
        }

        $d_from = new \DateTime($filter_from);
        $d_to   = new \DateTime($filter_to);

        if ($d_from > $d_to) {
            $filter_to = $filter_from;
            $d_to = clone $d_from;
        }

        if ($d_from->diff($d_to)->days > 365) {
            $d_to = clone $d_from;
            $d_to->modify('+365 days');
            $filter_to = $d_to->format('Y-m-d');
        }

        $filter_city = in_array($filter_city, ['all', 'Hannover', 'Laatzen'], true) ? $filter_city : 'all';

        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => ['confirmed', 'completed'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'mphb_check_in_date',
                    'value'   => [$filter_from, $filter_to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => '_mphb_check_in_date',
                    'value'   => [$filter_from, $filter_to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ];

        $bookings = get_posts($args);
        $tax_data = [];
        $total_tax = 0.0;

        foreach ($bookings as $b) {
            $b_id = (int) $b->ID;

            $model_raw = (string) get_post_meta($b_id, '_bsbt_snapshot_model', true);
            if ($model_raw === '') {
                continue;
            }

            $model = strtoupper(str_replace('model_', '', $model_raw));
            if ($model !== 'A') {
                continue;
            }

            $date_in  = get_post_meta($b_id, 'mphb_check_in_date', true) ?: get_post_meta($b_id, '_mphb_check_in_date', true);
            $date_out = get_post_meta($b_id, 'mphb_check_out_date', true) ?: get_post_meta($b_id, '_mphb_check_out_date', true);

            $year = (int) date('Y', strtotime((string) $date_in));
            $dates_str = date('d.m.Y', strtotime((string) $date_in)) . ' - ' . date('d.m.Y', strtotime((string) $date_out));

            $gross  = (float) get_post_meta($b_id, '_bsbt_snapshot_guest_total', true);
            $nights = (int) get_post_meta($b_id, '_bsbt_snapshot_nights', true);

            $adults = 0;
            if (function_exists('MPHB')) {
                $booking_obj = \MPHB()->getBookingRepository()->findById($b_id);
                if ($booking_obj && method_exists($booking_obj, 'getReservedRooms')) {
                    foreach ($booking_obj->getReservedRooms() as $res_room) {
                        if (method_exists($res_room, 'getAdults')) {
                            $adults += (int) $res_room->getAdults();
                        }
                    }
                }
            }

            if ($adults <= 0) {
                $adults = (int) (get_post_meta($b_id, 'mphb_adults', true) ?: get_post_meta($b_id, '_mphb_adults', true));
            }
            if ($adults <= 0) {
                $adults = 1;
            }

            $room_id = (int) get_post_meta($b_id, '_bsbt_snapshot_room_type_id', true);

            $address = '';
            if (function_exists('get_field')) {
                $address = get_field('field_68fccddecdffd', $room_id) ?: get_field('bsbt_address', $room_id) ?: get_field('adresse', $room_id);
            }
            if (empty($address)) {
                $address = get_post_meta($room_id, 'bsbt_address', true) ?: get_post_meta($room_id, 'adresse', true);
            }

            $city_meta  = strtolower((string) (get_post_meta($room_id, 'bsbt_city', true) ?: get_post_meta($room_id, 'stadt', true) ?: ''));
            $room_title = strtolower((string) get_the_title($room_id));

            $jurisdiction = 'Hannover';
            if (
                strpos($city_meta, 'laatzen') !== false ||
                strpos(strtolower((string) $address), 'laatzen') !== false ||
                strpos($room_title, 'laatzen') !== false
            ) {
                $jurisdiction = 'Laatzen';
            }

            if ($filter_city !== 'all' && $jurisdiction !== $filter_city) {
                continue;
            }
            if ($nights <= 0 || $gross <= 0) {
                continue;
            }

            $price_per_person = $gross / ($adults * $nights);
            $tax_rate = 0.0;

            if ($price_per_person <= 10.00) {
                $tax_rate = 0.50;
            } elseif ($price_per_person <= 25.00) {
                $tax_rate = 1.50;
            } elseif ($price_per_person <= 50.00) {
                $tax_rate = 3.00;
            } elseif ($price_per_person <= 100.00) {
                $tax_rate = 4.00;
            } elseif ($price_per_person <= 150.00) {
                $tax_rate = 5.00;
            } else {
                $extra = (int) ceil(($price_per_person - 150) / 50);
                $tax_rate = 5.00 + ($extra * 1.00);
            }

            if ($year >= 2026 && $price_per_person > 450.00) {
                $tax_rate = 12.00;
            }

            $booking_tax = $tax_rate * $adults * $nights;
            $total_tax += $booking_tax;

            $tax_data[] = [
                'id'       => $b_id,
                'edit_url' => admin_url("post.php?post={$b_id}&action=edit"),
                'room_id'  => $room_id,
                'address'  => (string) $address,
                'dates'    => $dates_str,
                'city'     => $jurisdiction,
                'gross'    => $gross,
                'adults'   => $adults,
                'nights'   => $nights,
                'ppn'      => $price_per_person,
                'rate'     => $tax_rate,
                'total'    => $booking_tax,
            ];
        }

        ?>
        <div style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
            <div class="sf-no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e2e8f0;">
                <form method="get" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="stayflow-finance">
                    <input type="hidden" name="sftab" value="citytax">

                    <label><strong>Stadt:</strong></label>
                    <select name="f_city" style="border-radius:6px;">
                        <option value="all" <?php selected($filter_city, 'all'); ?>>Alle Städte</option>
                        <option value="Hannover" <?php selected($filter_city, 'Hannover'); ?>>Hannover</option>
                        <option value="Laatzen" <?php selected($filter_city, 'Laatzen'); ?>>Laatzen</option>
                    </select>

                    <label><strong>Check-in von:</strong></label>
                    <input type="date" id="sf_tax_from" name="tax_from" value="<?php echo esc_attr($filter_from); ?>" style="border-radius:6px;" required>

                    <label><strong>bis:</strong></label>
                    <input type="date" id="sf_tax_to" name="tax_to" value="<?php echo esc_attr($filter_to); ?>" style="border-radius:6px;" required>

                    <button type="submit" class="button button-primary" style="background:#082567;">Filtern</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=stayflow-finance&sftab=citytax')); ?>" class="button">Reset</a>
                </form>

                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="window.print()" class="button" style="border-color:#ff9000; color:#000;">🖨 Drucken</button>
                    <button type="button" onclick="exportCityTaxCSV()" class="button button-primary" style="background:#10b981; border:none;">📗 CSV Export</button>
                </div>
            </div>

            <div class="sf-print-only" style="display:none; margin-bottom:20px;">
                <h2 style="margin:0;">Beherbergungsteuer Bericht</h2>
                <p>Stadt: <?php echo $filter_city === 'all' ? 'Alle' : esc_html($filter_city); ?> | Zeitraum: <?php echo esc_html($filter_from); ?> bis <?php echo esc_html($filter_to); ?></p>
            </div>

            <table class="wp-list-table widefat fixed striped" id="sf-citytax-table">
                <thead>
                    <tr style="background:#000;">
                        <th style="color:#ff9000; width:10%;">Buchungs-ID</th>
                        <th style="color:#ff9000; width:12%;">Zeitraum</th>
                        <th style="color:#ff9000; width:25%;">Objekt-ID &amp; Adresse</th>
                        <th style="color:#ff9000;">Stadt</th>
                        <th style="color:#ff9000;">Erw. x Nächte</th>
                        <th style="color:#ff9000;">Basis (pro Pers/Nacht)</th>
                        <th style="color:#ff9000;">Steuersatz</th>
                        <th style="color:#ff9000;">Steuer Gesamt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tax_data)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:20px;">Keine Daten gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tax_data as $t): ?>
                            <tr>
                                <td><strong><a href="<?php echo esc_url($t['edit_url']); ?>">#<?php echo (int) $t['id']; ?></a></strong></td>
                                <td><?php echo esc_html((string) $t['dates']); ?></td>
                                <td>
                                    <strong>Objekt-ID: <?php echo (int) $t['room_id']; ?></strong><br>
                                    <small style="color:#64748b;"><?php echo esc_html((string) $t['address']); ?></small>
                                </td>
                                <td><span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:11px;"><b><?php echo esc_html((string) $t['city']); ?></b></span></td>
                                <td><?php echo (int) $t['adults']; ?> Erw. × <?php echo (int) $t['nights']; ?> Nächte</td>
                                <td>€<?php echo esc_html(number_format((float) $t['ppn'], 2, ',', '.')); ?></td>
                                <td>€<?php echo esc_html(number_format((float) $t['rate'], 2, ',', '.')); ?></td>
                                <td style="color:#d63638; font-weight:bold;">€<?php echo esc_html(number_format((float) $t['total'], 2, ',', '.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold; border-top:2px solid #000;">
                            <td colspan="7" style="text-align:right;">GESAMTE BEHERBERGUNGSTEUER:</td>
                            <td style="color:#d63638; font-size:16px;">€<?php echo esc_html(number_format($total_tax, 2, ',', '.')); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * ==========================================
     * SECTION 3: DAC7 EXPORT
     * ==========================================
     */
    private function renderDac7Table(): void
    {
        $filter_year = isset($_GET['dac7_year']) ? (int) $_GET['dac7_year'] : (int) date('Y');

        $currentY = (int) date('Y');
        if ($filter_year < 2023 || $filter_year > $currentY + 1) {
            $filter_year = $currentY;
        }

        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => ['confirmed', 'completed', 'cancelled'],
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'mphb_check_in_date',
                    'value'   => [$filter_year . '-01-01', $filter_year . '-12-31'],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => '_mphb_check_in_date',
                    'value'   => [$filter_year . '-01-01', $filter_year . '-12-31'],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ];

        $bookings = get_posts($args);
        $hosts = [];

        foreach ($bookings as $b) {
            $b_id   = (int) $b->ID;
            $status = (string) get_post_status($b_id);

            if ($status === 'cancelled') {
                $snapshot = get_post_meta($b_id, '_bsbt_financial_snapshot', true);
                $refund_type = is_array($snapshot) ? (string) ($snapshot['refund_type'] ?? '') : '';
                if ($refund_type === '100%') {
                    continue;
                }
            }

            $room_id = (int) (
                get_post_meta($b_id, '_bsbt_snapshot_room_type_id', true)
                ?: get_post_meta($b_id, '_mphb_room_id', true)
                ?: get_post_meta($b_id, 'mphb_room_id', true)
            );

            $model_raw = (string) get_post_meta($b_id, '_bsbt_snapshot_model', true);
            if ($model_raw === '' && $room_id) {
                $model_raw = (string) get_post_meta($room_id, '_bsbt_business_model', true);
            }

            $model = strtoupper(str_replace('model_', '', $model_raw));
            if ($model !== 'B') {
                continue;
            }

            $owner_id = (int) get_post_meta($b_id, '_bsbt_snapshot_manager_user_id', true);
            if (!$owner_id && $room_id) {
                $owner_id = (int) (get_post_meta($room_id, 'bsbt_owner_id', true) ?: get_post_field('post_author', $room_id));
            }

            $gross  = (float) (get_post_meta($b_id, '_bsbt_snapshot_guest_total', true) ?: get_post_meta($b_id, '_mphb_total_price', true));
            $fees   = (float) get_post_meta($b_id, '_bsbt_snapshot_fee_gross_total', true);
            $nights = (int) get_post_meta($b_id, '_bsbt_snapshot_nights', true);
            if ($nights <= 0) {
                $nights = 1;
            }

            $tin = '';
            $vat = '';

            if ($owner_id) {
                $tin = (string) (get_user_meta($owner_id, 'bsbt_tax_number', true) ?: get_user_meta($owner_id, 'steuernummer', true));
                $vat = (string) get_user_meta($owner_id, 'sf_vat_id', true);
            }

            if ($tin === '' && $room_id) {
                $tin = (string) (get_post_meta($room_id, 'bsbt_tax_number', true) ?: get_post_meta($room_id, 'steuernummer', true));
                if ($tin === '' && function_exists('get_field')) {
                    $tin = (string) (get_field('bsbt_tax_number', $room_id) ?: get_field('steuernummer', $room_id));
                }
            }

            if ($vat === '' && $room_id) {
                $vat = (string) (get_post_meta($room_id, 'sf_vat_id', true) ?: get_post_meta($room_id, 'ust_id', true));
                if ($vat === '' && function_exists('get_field')) {
                    $vat = (string) (get_field('sf_vat_id', $room_id) ?: get_field('ust_id', $room_id));
                }
            }

            $host_name = '';
            if ($room_id && function_exists('get_field')) {
                $host_name = (string) get_field('field_68fcccdbcdffa', $room_id);
                if ($host_name === '') {
                    $host_name = (string) get_field('kontoinhaber', $room_id);
                }
            }

            if ($host_name === '' && $room_id) {
                $host_name = (string) get_post_meta($room_id, 'kontoinhaber', true);
            }

            if ($host_name === '' && $owner_id) {
                $owner_obj = get_userdata($owner_id);
                if ($owner_obj) {
                    $host_name = (string) $owner_obj->display_name;
                }
            }

            if ($host_name === '') {
                $host_name = 'Unbekannt';
            }

            $group_key = md5($host_name . $tin . $vat);

            if (!isset($hosts[$group_key])) {
                $hosts[$group_key] = [
                    'name'   => $host_name,
                    'tin'    => $tin !== '' ? $tin : '—',
                    'vat'    => $vat !== '' ? $vat : '—',
                    'count'  => 0,
                    'nights' => 0,
                    'gross'  => 0.0,
                    'fees'   => 0.0,
                ];
            }

            $hosts[$group_key]['count']++;
            $hosts[$group_key]['nights'] += $nights;
            $hosts[$group_key]['gross'] += $gross;
            $hosts[$group_key]['fees'] += $fees;
        }

        ?>
        <div style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div>
                    <h3 style="margin:0;">DAC7 (PStTG) Export - Modell B</h3>
                    <p class="description">Aggregierte Daten für das Bundeszentralamt für Steuern (BZSt).</p>
                </div>

                <form method="get" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="stayflow-finance">
                    <input type="hidden" name="sftab" value="dac7">
                    <label><strong>Jahr:</strong></label>
                    <select name="dac7_year" style="border-radius:6px;">
                        <?php for ($y = $currentY; $y >= 2023; $y--) : ?>
                            <option value="<?php echo (int) $y; ?>" <?php selected($filter_year, $y); ?>><?php echo (int) $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="button button-primary" style="background:#082567;">Filtern</button>
                </form>

                <button type="button" onclick="exportDac7CSV()" class="button button-primary sf-no-print" style="background:#10b981; border:none;">📁 CSV Export</button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="sf-dac7-table">
                <thead>
                    <tr style="background:#000;">
                        <th style="color:#ff9000;">Verkäufer (Name)</th>
                        <th style="color:#ff9000;">Steuer-ID (TIN)</th>
                        <th style="color:#ff9000;">USt-IdNr. (VAT)</th>
                        <th style="color:#ff9000;">Buchungen</th>
                        <th style="color:#ff9000;">Vermietete Nächte</th>
                        <th style="color:#ff9000;">Brutto-Umsatz (Vergütung)</th>
                        <th style="color:#ff9000;">Einbehaltene Gebühren</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hosts)): ?>
                        <tr><td colspan="7" style="text-align:center; padding:20px;">Keine Modell B Daten für dieses Jahr gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($hosts as $h): ?>
                            <tr>
                                <td><strong><?php echo esc_html((string) $h['name']); ?></strong></td>
                                <td><code style="background:#f1f5f9; padding:2px 4px;"><?php echo esc_html((string) $h['tin']); ?></code></td>
                                <td><code style="background:#f1f5f9; padding:2px 4px;"><?php echo esc_html((string) $h['vat']); ?></code></td>
                                <td><?php echo (int) $h['count']; ?></td>
                                <td><?php echo (int) $h['nights']; ?></td>
                                <td style="color:#1e7e34; font-weight:bold;">€<?php echo esc_html(number_format((float) $h['gross'], 2, ',', '.')); ?></td>
                                <td style="color:#d63638;">€<?php echo esc_html(number_format((float) $h['fees'], 2, ',', '.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderStyles(): void
    {
        ?>
        <style>
            .sf-tag-a { background:#082567; color:white; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:bold; }
            .sf-tag-b { background:#E0B849; color:#000; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:bold; }
            .nav-tab-wrapper { margin-top:15px; }
            .nav-tab { font-weight:600; transition:0.2s; border:none; border-bottom:2px solid transparent; margin-right:5px; }
            .nav-tab:hover { background:#f1f5f9; color:#000; }

            @media print {
                @page { margin:10mm; size:landscape; }
                #adminmenumain, #wpadminbar, #wpfooter, .sf-page-title, .nav-tab-wrapper, .sf-no-print { display:none !important; }
                #wpcontent { margin-left:0 !important; padding:0 !important; }
                .wrap { margin:0 !important; }
                body { background:#fff !important; font-size:10px !important; }
                .sf-print-only { display:block !important; margin-bottom:10px; }
                table { border-collapse:collapse; width:100%; table-layout:fixed; }
                th, td { border:1px solid #ddd; padding:4px; text-align:left; font-size:10px !important; word-wrap:break-word; }
                th { background-color:#f2f2f2 !important; color:#000 !important; -webkit-print-color-adjust:exact; }
                .sf-tag-a, .sf-tag-b { border:1px solid #000; color:#000 !important; background:transparent !important; padding:1px 2px; }
                a { text-decoration:none; color:#000 !important; }
                small { font-size:8px !important; }
            }
        </style>
        <?php
    }

    private function renderExportScripts(): void
    {
        ?>
        <script>
            function downloadCSV(csv, filename) {
                var csvFile = new Blob(["\uFEFF" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
                var downloadLink = document.createElement("a");
                downloadLink.download = filename;
                downloadLink.href = window.URL.createObjectURL(csvFile);
                downloadLink.style.display = "none";
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
            }

            function sanitizeCsvCell(value) {
                var data = String(value || '');
                data = data.replace(/(\r\n|\n|\r)/gm, ' ').trim();

                // CSV Formula Injection protection for Excel / LibreOffice
                if (/^[=\+\-@]/.test(data)) {
                    data = "'" + data;
                }

                data = data.replace(/"/g, '""');
                return '"' + data + '"';
            }

            function exportTable(tableId, baseFilename) {
                var csv = [];
                var rows = document.querySelectorAll("#" + tableId + " tr");

                for (var i = 0; i < rows.length; i++) {
                    var row = [];
                    var cols = rows[i].querySelectorAll("td, th");

                    for (var j = 0; j < cols.length; j++) {
                        var data = cols[j].innerText.replace(/€/g, '');
                        row.push(sanitizeCsvCell(data));
                    }

                    csv.push(row.join(";"));
                }

                var fromInput = document.getElementById('sf_date_from') || document.getElementById('sf_tax_from');
                var toInput = document.getElementById('sf_date_to') || document.getElementById('sf_tax_to');
                var dateFrom = fromInput ? fromInput.value : '';
                var dateTo = toInput ? toInput.value : '';
                var dateStr = (dateFrom || dateTo) ? "_" + dateFrom + "_to_" + dateTo : "_AllTime";

                downloadCSV(csv, baseFilename + dateStr + ".csv");
            }

            function exportPayoutsCSV() { exportTable('sf-payouts-table', 'StayFlow_Auszahlungen'); }
            function exportCityTaxCSV() { exportTable('sf-citytax-table', 'StayFlow_Beherbergungsteuer'); }
            function exportDac7CSV() { exportTable('sf-dac7-table', 'StayFlow_DAC7_Export'); }
        </script>
        <?php
    }
}