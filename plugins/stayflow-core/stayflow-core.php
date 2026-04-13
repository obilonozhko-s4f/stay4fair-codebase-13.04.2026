<?php
/**
 * Plugin Name: StayFlow Core
 * Description: Safe SaaS-ready core scaffold (no runtime integration).
 * Version: 0.9.0
 * Requires PHP: 8.1
 * Author: StayFlow
 * Text Domain: stayflow-core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const STAYFLOW_CORE_VERSION = '0.9.0';
const STAYFLOW_CORE_FILE = __FILE__;
const STAYFLOW_CORE_DIR = __DIR__;

/**
 * RU: Простая PSR-4 автозагрузка для пространства имен StayFlow\
 * EN: Simple PSR-4 autoloader for StayFlow\
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'StayFlow\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = STAYFLOW_CORE_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * RU: Регистрация activation/deactivation.
 * EN: Activation/deactivation registration.
 */
register_activation_hook(
    STAYFLOW_CORE_FILE,
    function (): void {
        require_once STAYFLOW_CORE_DIR . '/src/Core/Activator.php';
        \StayFlow\Core\Activator::activate();
    }
);

register_deactivation_hook(
    STAYFLOW_CORE_FILE,
    function (): void {
        require_once STAYFLOW_CORE_DIR . '/src/Core/Deactivator.php';
        \StayFlow\Core\Deactivator::deactivate();
    }
);

/**
 * RU: Инициализация ядра и системные хотфиксы.
 * EN: Kernel bootstrap and system hotfixes.
 */
add_action('plugins_loaded', static function (): void {
    // RU: Загрузка основного ядра
    if (class_exists('\\StayFlow\\Core\\Kernel')) {
        $kernel = new \StayFlow\Core\Kernel();
        $kernel->boot();
    }

    /**
     * RU: ХОТФИКС 1: Предотвращение Fatal Error (500) при использовании User Switching.
     * EN: HOTFIX 1: Prevent Fatal Error (500) when using User Switching.
     */
    $fixWcSessionForSwitching = static function(): void {
        if (function_exists('WC') && is_object(WC()) && empty(WC()->session)) {
            WC()->session = new class {
                public function forget_session(): void {}
                public function get_customer_id(): int { return 0; }
            };
        }
    };
    add_action('switch_to_user', $fixWcSessionForSwitching, 1);
    add_action('switch_back_user', $fixWcSessionForSwitching, 1);

    /**
     * RU: ХОТФИКС 2: Жесткий редирект при возврате админа (Switch Back).
     * EN: HOTFIX 2: Hard redirect on Admin Switch Back.
     * Принудительно выкидываем админа из фронтенд-дашборда обратно в таблицу Owners CRM.
     */
    add_action('switch_back_user', static function ($user_id): void {
        $user = get_user_by('id', $user_id);
        
        // Если юзер, к которому мы возвращаемся, имеет права админа
        if ($user && $user->has_cap('manage_options')) {
            // Делаем принудительный редирект в CRM и убиваем процесс (exit), 
            // чтобы плагин не перенаправил нас на старую страницу
            wp_safe_redirect(admin_url('admin.php?page=stayflow-owners'));
            exit;
        }
    }, 99);

}, 20);