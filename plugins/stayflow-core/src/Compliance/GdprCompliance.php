<?php

declare(strict_types=1);

namespace StayFlow\Compliance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.2.0
 *
 * RU:
 * GDPR / DSGVO — Экспорт и удаление персональных данных.
 *
 * Улучшения v1.2.0:
 * - Добавлен недостающий ключ _mphb_email.
 * - Retention-ключи владельца вынесены отдельно.
 * - Введена отдельная логика определения retention для бронирования.
 * - Убрано автоматическое приравнивание trash к налоговому retention.
 * - Анонимизация значений стала типо-специфичной (email/phone/name/address).
 * - Удаление PDF оставлено только для бронирований без retention.
 *
 * EN:
 * GDPR / DSGVO — Personal data export and erasure.
 * Improved with stricter retention and safer anonymization logic.
 */
final class GdprCompliance
{
    // -------------------------------------------------------------------------
    // Owner meta keys (usermeta)
    // -------------------------------------------------------------------------
    private const OWNER_META_KEYS = [
        'bsbt_iban',
        'bsbt_tax_number',
        'bsbt_account_holder',
        'bsbt_phone',
        'billing_address_1',
        'billing_address_2',
        'billing_postcode',
        'billing_city',
        'billing_country',
        'billing_phone',
        'billing_company',
        'kontonummer',
        'kontoinhaber',
        'steuernummer',
        'sf_company_name',
        'sf_vat_id',
    ];

    /**
     * RU: Поля владельца, которые не удаляются физически, а анонимизируются / псевдонимизируются
     * из-за возможной налоговой/бухгалтерской привязки.
     */
    private const OWNER_RETAINED_KEYS = [
        'bsbt_iban',
        'kontonummer',
        'bsbt_tax_number',
        'steuernummer',
        'sf_vat_id',
        'bsbt_account_holder',
        'kontoinhaber',
        'billing_company',
        'sf_company_name',
    ];

    // -------------------------------------------------------------------------
    // Guest meta keys (booking postmeta)
    // -------------------------------------------------------------------------
    private const GUEST_META_KEYS = [
        'mphb_first_name',
        'mphb_last_name',
        'mphb_email',
        '_mphb_email',
        'mphb_phone',
        '_mphb_phone',
        'mphb_address',
        'mphb_address1',
        'mphb_city',
        'mphb_zip',
        'mphb_country',
        'mphb_company',
        'mphb_note',
    ];

    /**
     * RU:
     * Статусы, которые по бизнес-логике считаются финализированными.
     * ВАЖНО: trash сюда не включаем автоматически.
     */
    private const FINALIZED_BOOKING_STATUSES = [
        'confirmed',
        'cancelled',
    ];

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerErasers']);
    }

    public function registerExporters(array $exporters): array
    {
        $exporters['stayflow-owner-data'] = [
            'exporter_friendly_name' => 'Stay4Fair – Vermieter-Daten',
            'callback'               => [$this, 'exportOwnerData'],
        ];

        $exporters['stayflow-guest-data'] = [
            'exporter_friendly_name' => 'Stay4Fair – Gast-Buchungsdaten',
            'callback'               => [$this, 'exportGuestData'],
        ];

        return $exporters;
    }

    public function registerErasers(array $erasers): array
    {
        $erasers['stayflow-owner-data'] = [
            'eraser_friendly_name' => 'Stay4Fair – Vermieter-Daten',
            'callback'             => [$this, 'eraseOwnerData'],
        ];

        $erasers['stayflow-guest-data'] = [
            'eraser_friendly_name' => 'Stay4Fair – Gast-Buchungsdaten',
            'callback'             => [$this, 'eraseGuestData'],
        ];

        return $erasers;
    }

    // -------------------------------------------------------------------------
    // Exporters
    // -------------------------------------------------------------------------

    public function exportOwnerData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', sanitize_email($email));
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $dataItems = [];

        foreach (self::OWNER_META_KEYS as $key) {
            $value = get_user_meta($user->ID, $key, true);
            if ($value !== '' && $value !== false && $value !== null) {
                $dataItems[] = [
                    'name'  => $key,
                    'value' => (string) $value,
                ];
            }
        }

        if ($dataItems === []) {
            return ['data' => [], 'done' => true];
        }

        return [
            'data' => [
                [
                    'group_id'    => 'stayflow-owner',
                    'group_label' => 'Stay4Fair Vermieter-Profil',
                    'item_id'     => 'owner-' . $user->ID,
                    'data'        => $dataItems,
                ],
            ],
            'done' => true,
        ];
    }

    public function exportGuestData(string $email, int $page = 1): array
    {
        $bookings = $this->findBookingsByEmail($email);
        if ($bookings === []) {
            return ['data' => [], 'done' => true];
        }

        $exportData = [];

        foreach ($bookings as $bookingId) {
            $dataItems = [];

            foreach (self::GUEST_META_KEYS as $key) {
                $value = get_post_meta($bookingId, $key, true);
                if ($value !== '' && $value !== false && $value !== null) {
                    $dataItems[] = [
                        'name'  => $key,
                        'value' => (string) $value,
                    ];
                }
            }

            if ($dataItems !== []) {
                $exportData[] = [
                    'group_id'    => 'stayflow-booking',
                    'group_label' => 'Stay4Fair Buchungsdaten',
                    'item_id'     => 'booking-' . $bookingId,
                    'data'        => $dataItems,
                ];
            }
        }

        return [
            'data' => $exportData,
            'done' => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Erasers
    // -------------------------------------------------------------------------

    public function eraseOwnerData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', sanitize_email($email));
        if (!$user) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $removed  = false;
        $retained = false;
        $messages = [];

        foreach (self::OWNER_META_KEYS as $key) {
            $value = get_user_meta($user->ID, $key, true);
            if ($value === '' || $value === false || $value === null) {
                continue;
            }

            if (in_array($key, self::OWNER_RETAINED_KEYS, true)) {
                update_user_meta($user->ID, $key, $this->anonymizeOwnerMetaValue($key, (string) $value, (int) $user->ID));
                $retained = true;
            } else {
                delete_user_meta($user->ID, $key);
                $removed = true;
            }
        }

        if ($retained) {
            $messages[] = 'Bestimmte Vermieter-Daten wurden aus steuerlichen / buchhalterischen Gründen anonymisiert und nicht vollständig gelöscht.';
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    public function eraseGuestData(string $email, int $page = 1): array
    {
        $bookings = $this->findBookingsByEmail($email);
        if ($bookings === []) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $removed  = false;
        $retained = false;
        $messages = [];

        foreach ($bookings as $bookingId) {
            $requiresRetention = $this->isBookingRetentionRequired($bookingId);

            foreach (self::GUEST_META_KEYS as $key) {
                $value = get_post_meta($bookingId, $key, true);
                if ($value === '' || $value === false || $value === null) {
                    continue;
                }

                if ($requiresRetention) {
                    update_post_meta(
                        $bookingId,
                        $key,
                        $this->anonymizeGuestMetaValue($key, (string) $value, $bookingId)
                    );
                    $retained = true;
                } else {
                    delete_post_meta($bookingId, $key);
                    $removed = true;
                }
            }

            if (!$requiresRetention) {
                if ($this->deletePhysicalPdfs($bookingId)) {
                    $messages[] = sprintf('Buchung #%d: PDF-Dokumente wurden gelöscht.', $bookingId);
                }
            } else {
                $messages[] = sprintf(
                    'Buchung #%d: Personenbezogene Daten wurden anonymisiert, da die Buchung als aufbewahrungspflichtig behandelt wird.',
                    $bookingId
                );
            }
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findBookingsByEmail(string $email): array
    {
        $safeEmail = sanitize_email($email);
        if (!is_email($safeEmail)) {
            return [];
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('mphb_email', '_mphb_email')
                   AND meta_value = %s",
                $safeEmail
            )
        );

        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    /**
     * RU:
     * Определяет, должна ли бронь удерживаться по бизнес-логике retention.
     * Сейчас базово считаем retention-required, если:
     * - статус финализирован
     * - или присутствует финансовый snapshot / order bridge
     *
     * Это безопаснее, чем ориентироваться только на post_status.
     */
    private function isBookingRetentionRequired(int $bookingId): bool
    {
        $status = get_post_status($bookingId);
        if (in_array($status, self::FINALIZED_BOOKING_STATUSES, true)) {
            return true;
        }

        $snapshotLockedAt = get_post_meta($bookingId, '_bsbt_snapshot_locked_at', true);
        if ($snapshotLockedAt !== '' && $snapshotLockedAt !== false && $snapshotLockedAt !== null) {
            return true;
        }

        $linkedOrderId = get_post_meta($bookingId, '_bsbt_order_id', true);
        if ($linkedOrderId !== '' && $linkedOrderId !== false && $linkedOrderId !== null) {
            return true;
        }

        return false;
    }

    private function anonymizeOwnerMetaValue(string $key, string $value, int $userId): string
    {
        switch ($key) {
            case 'bsbt_iban':
            case 'kontonummer':
                return 'DELETED-IBAN-' . $userId;

            case 'bsbt_tax_number':
            case 'steuernummer':
            case 'sf_vat_id':
                return 'DELETED-TAX-' . $userId;

            case 'bsbt_account_holder':
            case 'kontoinhaber':
                return 'GDPR Deleted';

            case 'billing_company':
            case 'sf_company_name':
                return 'GDPR Deleted Company';

            default:
                return '[GDPR-anonymisiert]';
        }
    }

    private function anonymizeGuestMetaValue(string $key, string $value, int $bookingId): string
    {
        switch ($key) {
            case 'mphb_email':
            case '_mphb_email':
                return 'deleted+' . $bookingId . '@anon.local';

            case 'mphb_phone':
            case '_mphb_phone':
                return 'deleted';

            case 'mphb_first_name':
                return 'GDPR';

            case 'mphb_last_name':
                return 'Deleted';

            case 'mphb_company':
                return 'GDPR Deleted Company';

            case 'mphb_address':
            case 'mphb_address1':
            case 'mphb_city':
            case 'mphb_zip':
            case 'mphb_country':
                return '[GDPR-anonymized]';

            case 'mphb_note':
                return '[GDPR note removed]';

            default:
                return '[GDPR-anonymisiert]';
        }
    }

    /**
     * RU:
     * Удаляет физические PDF-файлы (ваучеры и owner reports) для указанного бронирования.
     * Только для броней без retention.
     */
    private function deletePhysicalPdfs(int $bookingId): bool
    {
        $uploadDir = wp_upload_dir();
        if (empty($uploadDir['basedir']) || !is_string($uploadDir['basedir'])) {
            return false;
        }

        $deleted = false;

        // 1. Voucher PDFs: Voucher-1234-*.pdf
        $voucherDir = trailingslashit($uploadDir['basedir']) . 'bs-vouchers/';
        $vouchers = glob($voucherDir . 'Voucher-' . absint($bookingId) . '*.pdf');
        if (is_array($vouchers)) {
            foreach ($vouchers as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                    $deleted = true;
                }
            }
        }

        // 2. Owner PDFs: Owner_PDF_1234.pdf
        $ownerPdfDir = trailingslashit($uploadDir['basedir']) . 'bsbt-owner-pdf/';
        $ownerPdfs = glob($ownerPdfDir . 'Owner_PDF_' . absint($bookingId) . '.pdf');
        if (is_array($ownerPdfs)) {
            foreach ($ownerPdfs as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                    $deleted = true;
                }
            }
        }

        return $deleted;
    }
}