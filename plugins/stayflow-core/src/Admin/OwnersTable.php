<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\CPT\OwnerPostType;

/**
 * RU: Защита от прямого доступа.
 * EN: Protection against direct access.
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.16.0
 * RU: CRM-панель владельцев. Идеальная гигиена - esc_attr для всех tooltips и (string) для GET-параметров.
 * EN: Owners CRM panel. Perfect hygiene - esc_attr for all tooltips and (string) for GET params.
 */
final class OwnersTable
{
    /**
     * RU: Главный метод рендеринга страницы.
     * EN: Main page rendering method.
     */
    public function render(): void
    {
        // RU: Получаем поисковый запрос / EN: Get search query
        $search = isset($_GET['s']) ? sanitize_text_field((string)$_GET['s']) : '';
        
        // RU: Базовые аргументы поиска (роль owner)
        // EN: Base search arguments (owner role)
        $args = [
            'role'    => 'owner',
            'orderby' => 'registered',
            'order'   => 'DESC',
        ];

        // ==========================================
        // SECTION: SEARCH LOGIC / ЛОГИКА ПОИСКА
        // ==========================================
        if (!empty($search)) {
            $found_ids = [];

            // RU: Поиск по Email, логину и имени / EN: Search by Email, login, and name
            $u_query = new \WP_User_Query([
                'role'           => 'owner',
                'search'         => '*' . $search . '*',
                'search_columns' => ['user_login', 'user_email', 'display_name'],
                'fields'         => 'ID'
            ]);
            $found_ids = array_merge($found_ids, (array)$u_query->get_results());

            // RU: Поиск по мета-полям / EN: Search by meta fields
            $m_query = new \WP_User_Query([
                'role'       => 'owner',
                'fields'     => 'ID',
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => 'bsbt_phone', 'value' => $search, 'compare' => 'LIKE'],
                    ['key' => 'bsbt_iban', 'value' => $search, 'compare' => 'LIKE'],
                    ['key' => 'bsbt_tax_number', 'value' => $search, 'compare' => 'LIKE'],
                ]
            ]);
            $found_ids = array_merge($found_ids, (array)$m_query->get_results());

            $user_ids = array_unique($found_ids);
            $args['include'] = !empty($user_ids) ? $user_ids : [0];
        }

        $owners = get_users($args);

        // ==========================================
        // SECTION: HTML VIEW / ОТОБРАЖЕНИЕ
        // ==========================================
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title" style="color:#082567; font-weight:800; margin-bottom:20px;">👥 Owners Management Center</h1>
            
            <div class="sf-table-header-actions">
                <p class="description">Manage partners, verify compliance, and monitor properties.</p>
                <form method="get" class="sf-search-form">
                    <input type="hidden" name="page" value="stayflow-owners">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search ID, Name, Email, IBAN..." style="width:320px; border-radius:8px;">
                    <button type="submit" class="button button-primary">Search / Suchen</button>
                    <?php if ($search): ?>
                        <a href="admin.php?page=stayflow-owners" class="button">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                    <tr>
                        <th style="width: 22%;">Owner / Eigentümer</th>
                        <th>Account Status</th>
                        <th>Properties / Objekte</th>
                        <th>Business Model</th>
                        <th>Compliance / Payouts</th>
                        <th>Actions / Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($owners)): ?>
                        <tr><td colspan="6" style="text-align:center; padding: 20px;">No partners found.</td></tr>
                    <?php else: 
                        foreach ($owners as $user): 
                            $this->renderRow($user);
                        endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->renderStyles();
    }

    /**
     * RU: Рендеринг строки владельца.
     * EN: Rendering owner row.
     */
    private function renderRow(\WP_User $user): void
    {
        $userId = $user->ID;
        $status = get_user_meta($userId, '_sf_account_status', true) ?: 'pending';
        
        // RU: Статистика объектов MPHB. Строгое сравнение строк %s.
        // EN: MPHB property statistics. Strict string comparison %s.
        global $wpdb;
        $apts = $wpdb->get_results($wpdb->prepare("
            SELECT post_status, ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND (p.post_author = %d OR pm.meta_value = %s)
        ", $userId, (string)$userId));

        $counts = ['publish' => 0];
        $models = [];
        foreach ($apts as $apt) {
            if ($apt->post_status === 'publish') $counts['publish']++;
            $m = get_post_meta($apt->ID, '_bsbt_business_model', true) ?: 'model_a';
            $models[] = strtoupper(str_replace('model_', '', $m));
        }
        $modelText = empty($models) ? '—' : implode(' / ', array_unique($models));

        // RU: Данные комплаенса (3 иконки) / EN: Compliance data (3 icons)
        $iban = get_user_meta($userId, 'bsbt_iban', true);
        $tax  = get_user_meta($userId, 'bsbt_tax_number', true);
        $vat  = get_user_meta($userId, 'sf_vat_id', true);

        $ownerPost = get_posts(['post_type' => OwnerPostType::POST_TYPE, 'author' => $userId, 'posts_per_page' => 1]);
        $editUrl = !empty($ownerPost) ? get_edit_post_link($ownerPost[0]->ID) : admin_url('user-edit.php?user_id=' . $userId);
        
        ?>
        <tr>
            <td class="column-primary">
                <strong><a href="<?php echo esc_url((string)$editUrl); ?>"><?php echo esc_html($user->display_name); ?></a></strong>
                <div class="sf-owner-sub">ID: #<?php echo $userId; ?></div>
                <div class="sf-owner-email"><?php echo esc_html($user->user_email); ?></div>
            </td>
            <td>
                <span class="sf-badge <?php echo ($status === 'verified') ? 'badge-active' : 'badge-pending'; ?>">
                    <?php echo ($status === 'verified') ? '🟢 Verified' : '🟡 Pending'; ?>
                </span>
            </td>
            <td>
                <span class="sf-stat"><b><?php echo $counts['publish']; ?></b> Active</span>
            </td>
            <td>
                <span class="sf-model-tag"><?php echo esc_html($modelText); ?></span>
            </td>
            <td>
                <div class="sf-compliance-icons">
                    <span class="sf-tooltip-trigger <?php echo $iban ? 'is-ok' : 'is-empty'; ?>" data-info="<?php echo esc_attr('IBAN: ' . ($iban ?: 'Missing')); ?>">🏦</span>
                    <span class="sf-tooltip-trigger <?php echo $tax ? 'is-ok' : 'is-empty'; ?>" data-info="<?php echo esc_attr('Tax No: ' . ($tax ?: 'Missing')); ?>">📄</span>
                    <span class="sf-tooltip-trigger <?php echo $vat ? 'is-ok' : 'is-empty'; ?>" data-info="<?php echo esc_attr('VAT ID: ' . ($vat ?: 'Missing')); ?>">🏢</span>
                </div>
            </td>
            <td>
                <div class="sf-row-actions">
                    <a href="<?php echo admin_url('edit.php?post_type=mphb_room_type&author=' . $userId); ?>" class="button button-small">Properties</a>
                    
                    <?php 
                    /**
                     * RU: ПРЯМАЯ ГЕНЕРАЦИЯ ССЫЛКИ ДЛЯ USER SWITCHING
                     * EN: DIRECT GENERATION OF USER SWITCHING LINK
                     */
                    if (class_exists('user_switching') && $userId !== get_current_user_id()) : 
                        $switch_url = wp_nonce_url(
                            add_query_arg(['action' => 'switch_to_user', 'user_id' => $userId], admin_url()),
                            "switch_to_user_{$userId}"
                        );
                        ?>
                        <a href="<?php echo esc_url($switch_url); ?>" class="button button-small sf-btn-switch" style="margin-left: 5px;">Switch To</a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * RU: Вспомогательные стили оформления.
     * EN: Helper layout styles.
     */
    private function renderStyles(): void
    {
        ?>
        <style>
            .sf-table-header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; }
            .sf-search-form { display: flex; gap: 8px; }
            .sf-owner-sub { font-size: 10px; color: #94a3b8; }
            .sf-owner-email { font-size: 11px; color: #64748b; }
            .sf-model-tag { background: #082567; color: #E0B849; padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
            .sf-compliance-icons { display: flex; gap: 10px; }
            .sf-tooltip-trigger { font-size: 20px; cursor: help; position: relative; opacity: 0.15; filter: grayscale(1); transition: 0.2s; }
            .sf-tooltip-trigger.is-ok { opacity: 1; filter: none; }
            .sf-tooltip-trigger:hover::after {
                content: attr(data-info); position: absolute; bottom: 125%; left: 50%; transform: translateX(-50%);
                background: #334155; color: #fff; padding: 5px 10px; border-radius: 6px; font-size: 11px; white-space: nowrap; z-index: 100;
            }
            .badge-active { background: #dcfce7 !important; color: #166534 !important; border-radius: 12px; padding: 2px 8px; font-size: 11px; }
            .badge-pending { background: #fef9c3 !important; color: #854d0e !important; border-radius: 12px; padding: 2px 8px; font-size: 11px; }
            .sf-btn-switch:hover { background: #082567 !important; color: #E0B849 !important; border-color: #082567 !important; }
        </style>
        <?php
    }
}