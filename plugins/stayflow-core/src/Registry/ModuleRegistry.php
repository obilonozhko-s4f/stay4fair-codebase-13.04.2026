<?php

declare(strict_types=1);

namespace StayFlow\Registry;

/**
 * Version: 1.3.0
 * RU: Реестр модулей. Удалены Compliance и Models, добавлен Finance Hub.
 * EN: Module registry. Removed Compliance and Models, added Finance Hub.
 */
final class ModuleRegistry
{
    public static function all(): array
    {
        return [
            self::make(
                'settings',
                '⚙',
                'Settings',
                'Platform configuration',
                'active',
                'admin.php?page=stayflow-core-settings'
            ),
            self::make(
                'content',
                '🧱',
                'Content Registry',
                'Centralized content',
                'active',
                'admin.php?page=stayflow-core-content-registry'
            ),
            self::make(
                'policies',
                '📜',
                'Policies',
                'Cancellation rules',
                'active',
                'admin.php?page=stayflow-core-policies'
            ),
            self::make(
                'owners',
                '👤',
                'Owners',
                'Owner management',
                'active',
                'admin.php?page=stayflow-owners'
            ),
            // RU: Наш новый Finance Hub / EN: Our new Finance Hub
            self::make(
                'finance',
                '📊',
                'Finance & Taxes',
                'Payouts, City Tax & DAC7',
                'active',
                'admin.php?page=stayflow-finance'
            ),
        ];
    }

    private static function make(string $k, string $i, string $t, string $d, string $s, string $l): array {
        return ['key' => $k, 'icon' => $i, 'title' => $t, 'desc' => $d, 'status' => $s, 'link' => $l];
    }
}