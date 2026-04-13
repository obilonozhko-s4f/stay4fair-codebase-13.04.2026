<?php
declare(strict_types=1);

namespace StayFlow\Booking;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.4.0
 * RU: Менеджер отмен. Жесткая привязка к wp_salt, удалены предсказуемые fallback-значения.
 * EN: Cancellation Manager. Strict binding to wp_salt, removed predictable fallback values.
 */
final class CancellationManager
{
    private string $secretKey;

    public function __construct() {
        // RU: Используем только криптографически стойкую соль WordPress. Никаких хардкодов.
        // EN: Using only cryptographically strong WordPress salt. No hardcoded fallbacks.
        $this->secretKey = wp_salt('stayflow_cancel');
    }

    // ==========================================
    // Core Logic
    // ==========================================

    /**
     * RU: Нормализация email для исключения ошибок при сравнении.
     * EN: Normalize email to prevent comparison errors.
     */
    private function normalizeEmail(string $email): string {
        $clean = sanitize_email(trim($email));
        return is_email($clean) ? strtolower($clean) : '';
    }

    /**
     * RU: Получение и нормализация email из БД.
     * EN: Fetch and normalize email from DB.
     */
    public function getBookingEmail(int $bookingId): string {
        if (function_exists('MPHB')) {
            try {
                $booking = \MPHB()->getBookingRepository()->findById($bookingId);
                if ($booking && $booking->getCustomer()) {
                    return $this->normalizeEmail($booking->getCustomer()->getEmail());
                }
            } catch (\Exception $e) {
                // Silently fallback
            }
        }
        $metaEmail = (string) get_post_meta($bookingId, 'mphb_email', true);
        return $this->normalizeEmail($metaEmail);
    }

    /**
     * RU: Генерация токена ТОЛЬКО по ID. Email подтягивается строго из БД.
     * EN: Generate token strictly by ID. Email is fetched from DB.
     */
    public function generateToken(int $bookingId): string {
        $email = $this->getBookingEmail($bookingId);
        
        // RU: Если email нет в БД, мы не можем выдать валидный токен отмены.
        // EN: If no email in DB, we cannot issue a valid cancellation token.
        if (empty($email)) {
            return '';
        }

        // RU: Явный разделитель для надежности payload.
        // EN: Explicit delimiter for payload reliability.
        $payload = $bookingId . '|' . $email;
        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    /**
     * RU: Строгая валидация токена.
     * EN: Strict token validation.
     */
    public function validateToken(string $token, int $bookingId): bool {
        // RU: Hardening: Проверка формата токена (sha256 = 64 hex symbols).
        // EN: Hardening: Token format check.
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return false;
        }

        $expectedToken = $this->generateToken($bookingId);
        if (empty($expectedToken)) {
            return false;
        }

        return hash_equals($expectedToken, $token);
    }

    /**
     * RU: Получение статуса отмены (штрафы/сроки).
     * EN: Get cancellation status (penalties/deadlines).
     */
    public function getCancellationStatus(int $bookingId): array
    {
        if (!function_exists('MPHB')) throw new \Exception('MPHB not found.');

        $booking = \MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking) throw new \Exception('Booking not found.');

        $checkInDate = $booking->getCheckInDate();
        $reservedRooms = $booking->getReservedRooms();
        $roomTypeId = !empty($reservedRooms) ? $reservedRooms[0]->getRoomTypeId() : 0;
        
        $policyType = get_post_meta((int)$roomTypeId, '_sf_cancellation_policy', true);
        $cancellationDays = (int) get_post_meta((int)$roomTypeId, '_sf_cancellation_days', true);

        if ($policyType === 'non_refundable' || $cancellationDays <= 0) {
            return [
                'is_free'       => false,
                'deadline_date' => '1970-01-01 00:00:00',
                'penalty'       => 100,
                'check_in'      => $checkInDate->format('Y-m-d')
            ];
        }

        $deadlineDate = clone $checkInDate;
        $deadlineDate->modify("-{$cancellationDays} days");
        $deadlineDate->setTime(23, 59, 59);

        $now = new \DateTime('now', wp_timezone());
        $isFree = $now <= $deadlineDate;

        return [
            'is_free'       => $isFree,
            'deadline_date' => $deadlineDate->format('Y-m-d H:i:s'),
            'penalty'       => $isFree ? 0 : 100,
            'check_in'      => $checkInDate->format('Y-m-d')
        ];
    }
}