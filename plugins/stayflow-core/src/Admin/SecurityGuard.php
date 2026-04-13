<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.4.0
 *
 * RU:
 * SecurityGuard для owner login:
 * - Rate limit
 * - Brute-force lockout
 * - Email уведомление о блокировке
 * - Безопасное определение IP с учетом доверенных proxy
 *
 * Критический фикс:
 * НЕЛЬЗЯ безусловно доверять CF-Connecting-IP / X-Forwarded-For / X-Real-IP.
 * Эти заголовки читаются только если REMOTE_ADDR относится к доверенному proxy.
 *
 * Доверенные proxy можно задать:
 * 1) через константу BSBT_TRUSTED_PROXIES
 * 2) через фильтр stayflow/security/trusted_proxies
 *
 * Поддерживаемые форматы:
 * - одиночный IP: 173.245.48.5
 * - CIDR: 173.245.48.0/20
 *
 * EN:
 * Owner login protection with safe client IP resolution behind trusted proxies only.
 */
final class SecurityGuard
{
    private const OWNER_LOGIN_SLUG   = 'owner-login';
    private const MAX_FAILS          = 5;
    private const LOCK_MINUTES       = 15;
    private const MIN_INTERVAL_SEC   = 3;
    private const EMAIL_THROTTLE_MIN = 30;

    /**
     * RU: Локальный debug-лог. Держать false в production.
     */
    private const DEBUG_LOG = false;

    public function register(): void
    {
        add_filter('authenticate', [$this, 'guardBeforeAuth'], 1, 3);
        add_action('wp_login_failed', [$this, 'onLoginFailed'], 10, 1);
        add_action('wp_login', [$this, 'onLoginSuccess'], 10, 2);
    }

    /**
     * @param mixed $user
     * @param mixed $username
     * @param mixed $password
     * @return mixed
     */
    public function guardBeforeAuth($user, $username, $password)
    {
        if (!$this->isOwnerLoginRequest()) {
            return $user;
        }

        $ip = $this->getIp();
        if ($ip === '') {
            return $user;
        }

        $username = is_string($username) ? trim($username) : '';
        $idLogin  = $username !== '' ? mb_strtolower($username) : '__unknown__';

        // 1) Rate limiting по IP
        $rlKey = $this->key('rl', $ip);
        $now   = time();
        $last  = (int) get_transient($rlKey);

        if ($last > 0 && ($now - $last) < self::MIN_INTERVAL_SEC) {
            $this->log('RATE_LIMIT', [
                'ip'       => $this->maskIp($ip),
                'username' => $this->maskLogin($idLogin),
            ]);

            return new WP_Error(
                'bsbt_auth_failed',
                'Anmeldung fehlgeschlagen. Bitte warten Sie kurz und versuchen Sie es erneut.'
            );
        }

        set_transient($rlKey, $now, self::MIN_INTERVAL_SEC + 2);

        // 2) Lockout по IP + login identifier
        $lockKey     = $this->key('lock', $ip . '|' . $idLogin);
        $lockedUntil = (int) get_transient($lockKey);

        if ($lockedUntil > $now) {
            $mins = (int) ceil(($lockedUntil - $now) / 60);

            $this->log('LOCKED_ACCESS_DENIED', [
                'ip'       => $this->maskIp($ip),
                'username' => $this->maskLogin($idLogin),
            ]);

            return new WP_Error(
                'bsbt_auth_failed',
                'Anmeldung fehlgeschlagen. Bitte versuchen Sie es in ' . $mins . ' Min. erneut.'
            );
        }

        return $user;
    }

    /**
     * @param mixed $username
     */
    public function onLoginFailed($username): void
    {
        if (!$this->isOwnerLoginRequest()) {
            return;
        }

        $ip = $this->getIp();
        if ($ip === '') {
            return;
        }

        $idLogin = is_string($username) ? mb_strtolower(trim($username)) : '__unknown__';

        $failKey = $this->key('fails', $ip . '|' . $idLogin);
        $fails   = (int) get_transient($failKey);
        $fails++;

        set_transient($failKey, $fails, self::LOCK_MINUTES * MINUTE_IN_SECONDS);

        $this->log('LOGIN_FAIL', [
            'ip'    => $this->maskIp($ip),
            'user'  => $this->maskLogin($idLogin),
            'count' => $fails,
        ]);

        if ($fails >= self::MAX_FAILS) {
            $lockKey = $this->key('lock', $ip . '|' . $idLogin);
            set_transient(
                $lockKey,
                time() + (self::LOCK_MINUTES * MINUTE_IN_SECONDS),
                self::LOCK_MINUTES * MINUTE_IN_SECONDS
            );

            $this->log('LOCKOUT_TRIGGERED', [
                'ip'   => $this->maskIp($ip),
                'user' => $this->maskLogin($idLogin),
            ]);

            $this->sendLockNotification($idLogin, $ip);
        }
    }

    /**
     * @param mixed $userLogin
     * @param mixed $user
     */
    public function onLoginSuccess($userLogin, $user): void
    {
        if (!$this->isOwnerLoginRequest()) {
            return;
        }

        $ip = $this->getIp();
        if ($ip === '') {
            return;
        }

        $idLogin = is_string($userLogin) ? mb_strtolower(trim($userLogin)) : '__unknown__';

        delete_transient($this->key('fails', $ip . '|' . $idLogin));
        delete_transient($this->key('lock', $ip . '|' . $idLogin));

        $this->log('LOGIN_SUCCESS_CLEARED', [
            'ip'   => $this->maskIp($ip),
            'user' => $this->maskLogin($idLogin),
        ]);
    }

    /**
     * RU:
     * Безопасное определение client IP:
     * 1) берем REMOTE_ADDR
     * 2) если REMOTE_ADDR входит в trusted proxies — только тогда читаем proxy headers
     * 3) иначе используем только REMOTE_ADDR
     */
    private function getIp(): string
    {
        $remoteAddr = $this->normalizeIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return '';
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        // 1) Cloudflare
        $cfIp = $this->normalizeIp((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cfIp !== '' && $this->isPublicIp($cfIp)) {
            return $cfIp;
        }

        // 2) X-Forwarded-For: первый валидный public IP слева направо
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = explode(',', $xff);
            foreach ($parts as $part) {
                $candidate = $this->normalizeIp($part);
                if ($candidate !== '' && $this->isPublicIp($candidate)) {
                    return $candidate;
                }
            }
        }

        // 3) X-Real-IP
        $realIp = $this->normalizeIp((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '' && $this->isPublicIp($realIp)) {
            return $realIp;
        }

        return $remoteAddr;
    }

    private function normalizeIp(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private function isPublicIp(string $ip): bool
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
     * RU:
     * Возвращает список trusted proxies.
     *
     * Форматы:
     * - ['173.245.48.0/20', '103.21.244.0/22', '192.168.1.10']
     */
    private function getTrustedProxies(): array
    {
        $configured = [];

        if (defined('BSBT_TRUSTED_PROXIES') && is_array(constant('BSBT_TRUSTED_PROXIES'))) {
            $configured = constant('BSBT_TRUSTED_PROXIES');
        }

        /**
         * RU: Позволяет задать trusted proxies через MU/plugin/theme config.
         *
         * @param array<int, string> $configured
         */
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

    private function isTrustedProxy(string $ip): bool
    {
        $trusted = $this->getTrustedProxies();
        if ($trusted === []) {
            return false;
        }

        foreach ($trusted as $entry) {
            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatches(string $ip, string $rule): bool
    {
        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $ip);
        }

        [$subnet, $mask] = array_pad(explode('/', $rule, 2), 2, '');
        $subnet = $this->normalizeIp($subnet);
        $mask   = trim($mask);

        if ($subnet === '' || $mask === '' || !ctype_digit($mask)) {
            return false;
        }

        $maskBits = (int) $mask;

        // IPv4 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
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

        // IPv6 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
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

    private function sendLockNotification(string $username, string $ip): void
    {
        $throttleKey = $this->key('email_sent', $username . '|' . $ip);
        if (get_transient($throttleKey)) {
            $this->log('EMAIL_THROTTLED', [
                'user' => $this->maskLogin($username),
                'ip'   => $this->maskIp($ip),
            ]);
            return;
        }

        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        $to = [get_option('admin_email')];
        if ($user && !empty($user->user_email)) {
            $to[] = $user->user_email;
        }

        $to = array_values(array_unique(array_filter(array_map('sanitize_email', $to))));

        if ($to === []) {
            return;
        }

        $subject = 'Stay4Fair: Login-Sperre aktiv';
        $message = "Sicherheits-Benachrichtigung für Ihr Stay4Fair Partner-Portal.\n\n"
            . "Konto: " . $username . "\n"
            . "IP-Adresse: " . $ip . "\n"
            . "Status: Gesperrt für " . self::LOCK_MINUTES . " Minuten.\n\n"
            . "Falls Sie dies nicht waren, ändern Sie bitte Ihr Passwort.\n"
            . "Ihr Stay4Fair Team";

        if (wp_mail($to, $subject, $message)) {
            set_transient($throttleKey, 1, self::EMAIL_THROTTLE_MIN * MINUTE_IN_SECONDS);

            $this->log('EMAIL_SENT', [
                'to_count' => count($to),
                'user'     => $this->maskLogin($username),
                'ip'       => $this->maskIp($ip),
            ]);
        }
    }

    private function isOwnerLoginRequest(): bool
    {
        if (is_admin()) {
            return false;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) $_SERVER['REQUEST_URI']) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        $isUri  = str_contains($uri, '/' . strtolower(self::OWNER_LOGIN_SLUG));
        $isPost = ($method === 'POST' && isset($_POST['bsbt_login_submit']));

        return $isUri || $isPost;
    }

    private function key(string $prefix, string $raw): string
    {
        return 'bsbt_sg_' . $prefix . '_' . substr(md5($raw), 0, 16);
    }

    private function maskIp(string $ip): string
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

    private function maskLogin(string $login): string
    {
        $login = trim($login);
        if ($login === '' || $login === '__unknown__') {
            return '__unknown__';
        }

        $length = mb_strlen($login);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($login, 0, 1) . str_repeat('*', max(1, $length - 2)) . mb_substr($login, -1);
    }

    private function log(string $event, array $ctx = []): void
    {
        if (!self::DEBUG_LOG) {
            return;
        }

        error_log('[BSBT_SG] ' . $event . ' ' . wp_json_encode($ctx));
    }
}