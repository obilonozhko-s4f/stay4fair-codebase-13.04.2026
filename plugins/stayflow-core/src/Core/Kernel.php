<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/Core/Kernel.php
 * Version: 1.1.8
 * RU: Главное ядро инициализации плагина. Добавлен GdprCompliance для DSGVO.
 * EN: Main initialization kernel. Added GdprCompliance for DSGVO.
 */

declare(strict_types=1);

namespace StayFlow\Core;

use StayFlow\Admin\Menu;
use StayFlow\BusinessModel\BusinessModelServiceProvider;
use StayFlow\CPT\OwnerPostType;
use StayFlow\CPT\PropertyMeta;
use StayFlow\Integration\BsbtPolicyAdapter;
use StayFlow\FeatureFlags\FeatureFlagStore;
use StayFlow\Settings\SettingsStore;
use StayFlow\Onboarding\OnboardingProvider;
use StayFlow\Onboarding\OnboardingHandler;
use StayFlow\BusinessModel\InvoiceModifier;
use StayFlow\Api\CalendarApiController;
use StayFlow\Integration\CancelBookingShortcode;
use StayFlow\Booking\CancellationNotificationHandler;
use StayFlow\Support\SiteNoticeProvider;
use StayFlow\Support\SupportProvider;

if (!defined('ABSPATH')) {
    exit;
}

final class Kernel
{
    /**
     * RU: Запуск всех сервисов ядра.
     * EN: Booting all core services.
     */
    public function boot(): void
    {
        $settingsStore    = new SettingsStore();
        $ownerPostType    = new OwnerPostType();
        $propertyMeta     = new PropertyMeta();
        $rateSync         = new \StayFlow\BusinessModel\RateSyncService();
        $policyAdapter    = new BsbtPolicyAdapter();
        $featureFlagStore = new FeatureFlagStore();
        $menu             = new Menu();
        $calendarApi      = new CalendarApiController();
        
        $calendarApi->register();

        add_action('init', [$ownerPostType, 'register']);
        $propertyMeta->register(); 
        $rateSync->register();
        $policyAdapter->register();

        add_action('admin_init', [$settingsStore, 'register']);
        add_action('admin_init', [$featureFlagStore, 'register']);
        add_action('admin_menu', [$menu, 'register']);

        (new BusinessModelServiceProvider())->boot();

        (new OnboardingProvider())->register();
        (new OnboardingHandler($settingsStore))->register();
        (new \StayFlow\Onboarding\VerificationHandler())->register();
        (new \StayFlow\Integration\OwnerStepsShortcode())->register();
        
        (new \StayFlow\CPT\ApartmentProvider())->register();
        (new \StayFlow\CPT\ApartmentHandler())->register();
        (new \StayFlow\Admin\AccessGuard())->register();
        (new \StayFlow\Admin\SecurityGuard())->register();
        (new \StayFlow\Media\ImageOptimizer())->register();
        (new \StayFlow\CPT\ApartmentEditProvider())->register();
        (new \StayFlow\CPT\ApartmentEditHandler())->register();
        (new \StayFlow\CPT\ApartmentListProvider())->register();
        
        (new \StayFlow\Voucher\VoucherSender())->register();
        (new \StayFlow\Voucher\VoucherMetabox())->register();
        (new \StayFlow\CPT\OwnerCalendarProvider())->register();

        (new CancelBookingShortcode())->register();
        (new CancellationNotificationHandler())->register();
        (new \StayFlow\Legal\LegalManager())->register();

        // ==========================================
        // ДОБАВЛЯЕМ СЮДА НАШУ КАСТОМИЗАЦИЮ КНОПОК
        // ==========================================
        (new \StayFlow\Integration\MphbUiCustomizer())->register();
        
        // ==========================================
        // RU: БЕЗОПАСНАЯ ИНИЦИАЛИЗАЦИЯ (FALLBACKS)
        // EN: SAFE INITIALIZATION (FALLBACKS)
        // ==========================================
        if (class_exists('\StayFlow\CPT\OwnerProfileProvider')) {
            (new \StayFlow\CPT\OwnerProfileProvider())->register();
        }
        if (class_exists('\StayFlow\CPT\OwnerProfileHandler')) {
            (new \StayFlow\CPT\OwnerProfileHandler())->register();
        }
        
        if (class_exists('\StayFlow\Integration\ContractingPartyShortcode')) {
            (new \StayFlow\Integration\ContractingPartyShortcode())->register();
        }

        add_action('init', [InvoiceModifier::class, 'init']);

        (new SiteNoticeProvider())->register();
        
        // ==========================================
        // RU: РЕГИСТРАЦИЯ ЦЕНТРА ПОДДЕРЖКИ
        // EN: SUPPORT CENTER REGISTRATION
        // ==========================================
        (new SupportProvider())->register();

        // ==========================================
        // RU: ИНИЦИАЛИЗАЦИЯ ЛОКАЛЬНЫХ ШРИФТОВ (GDPR / DSGVO)
        // EN: LOCAL FONTS INITIALIZATION (GDPR / DSGVO)
        // ==========================================
        if (class_exists('\StayFlow\Media\FontLoader')) {
            \StayFlow\Media\FontLoader::init();
        }

        // ==========================================
        // RU: ЭКСПОРТ И УДАЛЕНИЕ ДАННЫХ (GDPR / DSGVO)
        // EN: DATA EXPORT AND ERASURE (GDPR / DSGVO)
        // ==========================================
        if (class_exists('\StayFlow\Compliance\GdprCompliance')) {
            (new \StayFlow\Compliance\GdprCompliance())->register();
        }
    }
}