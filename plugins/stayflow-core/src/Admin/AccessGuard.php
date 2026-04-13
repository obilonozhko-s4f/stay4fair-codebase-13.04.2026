<?php

declare(strict_types=1);

namespace StayFlow\Admin;

/**
 * Version: 1.0.1
 * RU: Защита админ-панели от несанкционированного доступа владельцев.
 * EN: Restricts wp-admin access for owner roles.
 */
final class AccessGuard
{
    /* ==========================================================================
     * REGISTER / РЕГИСТРАЦИЯ ХУКОВ
     * ========================================================================== */
    public function register(): void
    {
        add_action('admin_init', [$this, 'blockAdminAccess']);
        add_filter('show_admin_bar', [$this, 'hideAdminBar']);
    }

    /* ==========================================================================
     * BLOCK ADMIN ACCESS / БЛОКИРОВКА ДОСТУПА В АДМИНКУ
     * ========================================================================== */
    public function blockAdminAccess(): void
    {
        global $pagenow;

        // RU: Не блокируем AJAX, Cron и системную обработку форм (admin-post.php)
        // EN: Do not block AJAX, Cron, and system form handling (admin-post.php)
        if (wp_doing_ajax() || wp_doing_cron() || $pagenow === 'admin-post.php') {
            return;
        }

        $user = wp_get_current_user();
        
        // RU: Если это владелец (без прав управления настройками), отправляем в Дашборд
        // EN: If it's an owner (without manage_options cap), redirect to Dashboard
        if (in_array('owner', (array)$user->roles, true) && !current_user_can('manage_options')) {
            wp_safe_redirect(home_url('/owner-dashboard/'));
            exit;
        }
    }

    /* ==========================================================================
     * HIDE ADMIN BAR / СКРЫТИЕ ЧЕРНОЙ ПАНЕЛИ WORDPRESS
     * ========================================================================== */
    public function hideAdminBar(bool $show): bool
    {
        $user = wp_get_current_user();
        
        // RU: Скрываем верхнюю панель для владельцев
        // EN: Hide top admin bar for owners
        if (in_array('owner', (array)$user->roles, true) && !current_user_can('manage_options')) {
            return false; 
        }
        
        return $show;
    }
}