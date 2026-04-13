<?php

declare(strict_types=1);

namespace StayFlow\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.1.0
 *
 * RU:
 * Аудит-логгер для финансовых и security-событий.
 *
 * Улучшения v2.1.0:
 * - Исправлено небезопасное доверие proxy headers без trusted proxy check.
 * - Добавлена редакция чувствительных данных в context (PII / secrets / tokens).
 * - Добавлено ограничение глубины и длины логируемых значений.
 * - Сохранена полная обратная совместимость (legacy метод log).
 *
 * EN:
 * Audit logger for financial and security events.
 * Hardened with trusted-proxy-aware IP resolution and PII redaction.
 */
final class AuditLogger
{
    private const OPTION_FINANCIAL = 'stayflow_audit_financial';
    private const OPTION_DEBUG     = 'stayflow_audit_debug';

    private const MAX_FINANCIAL_ROWS = 1500;
    private const MAX_DEBUG_ROWS     = 500;

    private const MAX_STRING_LENGTH  = 500;
    private const MAX_ARRAY_DEPTH    = 4;

    /**
     * RU: Финансовые и критические события. Пишутся всегда.
     *
     * @param array<string,mixed> $context
     */
    public static function financial(string $event, array $context = []): void
    {
        self::write(self::OPTION_FINANCIAL, $event, $context, self::MAX_FINANCIAL_ROWS);
    }

    /**
     * RU: Отладочные события. Пишутся только если включен feature flag.
     *
     * @param array<string,mixed> $context
     */
    public static function debug(string $event, array $context = []): void
    {
        if (!self::isDebugEnabled()) {
            return;
        }

        self::write(self::OPTION_DEBUG, $event, $context, self::MAX_DEBUG_ROWS);
    }

    /**
     * RU: Обратная совместимость для legacy вызовов.
     *
     * @param array<string,mixed> $context
     */
    public static function log(string $event, array $context = []): void
    {
        self::debug($event, $context);
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    private static function isDebugEnabled(): bool
    {
        if (class_exists('\StayFlow\Core\FeatureFlags\FeatureFlagStore')) {
            $flags = new \StayFlow\Core\FeatureFlags\FeatureFlagStore();

            if (defined('\StayFlow\Core\FeatureFlags\FeatureFlagStore::FLAG_PHASE_4_LEGACY_CLEANUP_ENABLE')) {
                return $flags->isEnabled(\StayFlow\Core\FeatureFlags\FeatureFlagStore::FLAG_PHASE_4_LEGACY_CLEANUP_ENABLE);
            }
        }

        return get_option('stayflow_feature_phase_4_legacy_cleanup_enable', 'no') === 'yes';
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function write(string $optionKey, string $event, array $context, int $maxRows): void
    {
        $entries = get_option($optionKey, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'event'   => sanitize_key($event) !== '' ? sanitize_key($event) : self::sanitizeEventName($event),
            'context' => self::sanitizeContext($context),
            'user_id' => get_current_user_id(),
            'ip'      => self::maskIp(self::resolveIp()),
            'time'    => gmdate('Y-m-d H:i:s'),
        ];

        if (count($entries) > $maxRows) {
            $entries = array_slice($entries, -$maxRows);
        }

        update_option($optionKey, $entries, false);
    }

    private static function sanitizeEventName(string $event): string
    {
        $event = trim($event);
        if ($event === '') {
            return 'unknown_event';
        }

        $event = preg_replace('/[^A-Za-z0-9_\-:.]/', '_', $event) ?? 'unknown_event';
        return substr($event, 0, 120);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeContextValue($value, string $key = '', int $depth = 0)
    {
        if ($depth >= self::MAX_ARRAY_DEPTH) {
            return '[max-depth]';
        }

        $normalizedKey = strtolower(trim($key));

        // Секреты и чувствительные токены полностью редактируем
        if (self::isSecretLikeKey($normalizedKey)) {
            return '[redacted]';
        }

        // Массивы
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $subKey => $subValue) {
                $childKey = is_string($subKey) ? $subKey : (string) $subKey;
                $clean[$childKey] = self::sanitizeContextValue($subValue, $childKey, $depth + 1);
            }
            return $clean;
        }

        // Объекты
        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }

        // Bool / null / numeric
        if (is_bool($value) || $value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        // Остальное приводим к строке
        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }

        // Типовая редакция по ключу
        if (self::isEmailLikeKey($normalizedKey)) {
            return self::maskEmail($string);
        }

        if (self::isPhoneLikeKey($normalizedKey)) {
            return self::maskPhone($string);
        }

        if (self::isIbanLikeKey($normalizedKey)) {
            return self::maskIban($string);
        }

        if (self::isTaxLikeKey($normalizedKey)) {
            return self::maskGenericIdentifier($string, 4);
        }

        if (self::isAddressLikeKey($normalizedKey)) {
            return '[redacted-address]';
        }

        if (self::isNameLikeKey($normalizedKey)) {
            return self::maskName($string);
        }

        // Типовая редакция по значению
        if (is_email($string)) {
            return self::maskEmail($string);
        }

        if (self::looksLikeIban($string)) {
            return self::maskIban($string);
        }

        // Ограничиваем длину и убираем бинарный мусор / переводы строк
        $string = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $string) ?? $string;
        $string = preg_replace('/\s+/u', ' ', $string) ?? $string;

        if (mb_strlen($string) > self::MAX_STRING_LENGTH) {
            $string = mb_substr($string, 0, self::MAX_STRING_LENGTH) . '…';
        }

        return $string;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $safeKey = is_string($key) && $key !== '' ? $key : 'unknown';
            $clean[$safeKey] = self::sanitizeContextValue($value, $safeKey, 0);
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // IP resolution
    // -------------------------------------------------------------------------

    /**
     * RU:
     * Безопасное определение IP:
     * - proxy headers читаются только если REMOTE_ADDR — trusted proxy
     * - иначе используется только REMOTE_ADDR
     */
    private static function resolveIp(): string
    {
        $remoteAddr = self::normalizeIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return 'unknown';
        }

        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $cf = self::normalizeIp((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && self::isPublicIp($cf)) {
            return $cf;
        }

        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = explode(',', $xff);
            foreach ($parts as $part) {
                $candidate = self::normalizeIp($part);
                if ($candidate !== '' && self::isPublicIp($candidate)) {
                    return $candidate;
                }
            }
        }

        $realIp = self::normalizeIp((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '' && self::isPublicIp($realIp)) {
            return $realIp;
        }

        return $remoteAddr;
    }

    private static function normalizeIp(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private static function isPublicIp(string $ip): bool
    {
        if ($ip === '' || strlen($ip) > 45) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return array<int,string>
     */
    private static function getTrustedProxies(): array
    {
        $configured = [];

        if (defined('BSBT_TRUSTED_PROXIES') && is_array(constant('BSBT_TRUSTED_PROXIES'))) {
            $configured = constant('BSBT_TRUSTED_PROXIES');
        }

        $configured = apply_filters('stayflow/security/trusted_proxies', $configured);

        if (!is_array($configured)) {
            return [];
        }

        $result = [];
        foreach ($configured as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '' || strlen($entry) > 64) {
                continue;
            }

            $result[] = $entry;
        }

        return array_values(array_unique($result));
    }

    private static function isTrustedProxy(string $ip): bool
    {
        $trusted = self::getTrustedProxies();
        if ($trusted === []) {
            return false;
        }

        foreach ($trusted as $entry) {
            if (self::ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatches(string $ip, string $rule): bool
    {
        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $ip);
        }

        [$subnet, $mask] = array_pad(explode('/', $rule, 2), 2, '');
        $subnet = self::normalizeIp($subnet);
        $mask   = trim($mask);

        if ($subnet === '' || $mask === '' || !ctype_digit($mask)) {
            return false;
        }

        $maskBits = (int) $mask;

        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            if ($maskBits < 0 || $maskBits > 32) {
                return false;
            }

            $ipLong     = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = $maskBits === 0 ? 0 : (~0 << (32 - $maskBits));
            return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
        }

        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            if ($maskBits < 0 || $maskBits > 128) {
                return false;
            }

            $ipBin     = @inet_pton($ip);
            $subnetBin = @inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $bytes = intdiv($maskBits, 8);
            $bits  = $maskBits % 8;

            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }

            if ($bits === 0) {
                return true;
            }

            $maskByte = (~(0xff >> $bits)) & 0xff;

            return (
                (ord($ipBin[$bytes]) & $maskByte) ===
                (ord($subnetBin[$bytes]) & $maskByte)
            );
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Redaction helpers
    // -------------------------------------------------------------------------

    private static function isSecretLikeKey(string $key): bool
    {
        $needles = [
            'token', 'nonce', 'secret', 'password', 'pass', 'session',
            'cookie', 'authorization', 'auth', 'bearer', 'signature',
            'api_key', 'apikey', 'refresh_token', 'access_token',
        ];

        foreach ($needles as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isEmailLikeKey(string $key): bool
    {
        return strpos($key, 'email') !== false;
    }

    private static function isPhoneLikeKey(string $key): bool
    {
        return strpos($key, 'phone') !== false || strpos($key, 'mobile') !== false;
    }

    private static function isIbanLikeKey(string $key): bool
    {
        return strpos($key, 'iban') !== false || strpos($key, 'konto') !== false;
    }

    private static function isTaxLikeKey(string $key): bool
    {
        return strpos($key, 'tax') !== false || strpos($key, 'vat') !== false || strpos($key, 'steuer') !== false || strpos($key, 'tin') !== false;
    }

    private static function isAddressLikeKey(string $key): bool
    {
        return strpos($key, 'address') !== false || strpos($key, 'city') !== false || strpos($key, 'zip') !== false || strpos($key, 'postcode') !== false || strpos($key, 'country') !== false;
    }

    private static function isNameLikeKey(string $key): bool
    {
        return strpos($key, 'name') !== false || strpos($key, 'holder') !== false || strpos($key, 'inhaber') !== false;
    }

    private static function looksLikeIban(string $value): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);
        return (bool) preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $normalized);
    }

    private static function maskEmail(string $email): string
    {
        $email = trim($email);
        if (!is_email($email)) {
            return '[redacted-email]';
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '[redacted-email]';
        }

        $localMasked = mb_substr($local, 0, 1) . '***';
        return $localMasked . '@' . $domain;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '[redacted-phone]';
        }

        $tail = substr($digits, -2);
        return '***' . $tail;
    }

    private static function maskIban(string $iban): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $iban) ?? $iban);
        if ($normalized === '') {
            return '[redacted-iban]';
        }

        $prefix = substr($normalized, 0, 4);
        $suffix = substr($normalized, -4);

        return $prefix . '********' . $suffix;
    }

    private static function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '[redacted-name]';
        }

        $length = mb_strlen($name);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($name, 0, 1) . str_repeat('*', max(1, $length - 2)) . mb_substr($name, -1);
    }

    private static function maskGenericIdentifier(string $value, int $visibleTail = 4): string
    {
        $value = trim($value);
        if ($value === '') {
            return '[redacted-id]';
        }

        $tail = mb_substr($value, -$visibleTail);
        return '***' . $tail;
    }

    private static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = 'xxx';
                return implode('.', $parts);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 12) . '::xxxx';
        }

        return 'unknown';
    }
}