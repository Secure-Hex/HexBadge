<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Gestión segura de sesiones (CLAUDE.md §4.1).
 *
 * - Cookies secure + httponly + SameSite=Strict
 * - Regeneración de ID en login
 * - Timeout de inactividad (30 min)
 * - Solo se almacena user_id y role en sesión (nada sensible §14)
 */
final class Session
{
    private static bool $started = false;

    public static function start(string $name = 'HEXBADGE_SESS'): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        // La cookie es 'secure' si la petición llega por HTTPS. En producción
        // el sitio se sirve por HTTPS (cookie secure); en dev por HTTP no, lo
        // que permite probar localmente sin romper el manejo de sesión.
        $secure = self::isHttps();

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',          // host-only; no confiar en HTTP_HOST
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name($name);
        session_start();
        self::$started = true;

        self::enforceIdleTimeout();
    }

    /**
     * Detecta si la petición actual llega por HTTPS (directo o tras proxy).
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return is_string($proto) && strtolower($proto) === 'https';
    }

    /**
     * Cierra la sesión por inactividad si se supera el timeout.
     */
    private static function enforceIdleTimeout(): void
    {
        $timeout = (int) config('session.idle_timeout', 1800);
        $now     = time();
        $last    = $_SESSION['_last_activity'] ?? null;

        if (is_int($last) && ($now - $last) > $timeout) {
            self::destroy();
            session_start();
        }

        $_SESSION['_last_activity'] = $now;
    }

    /**
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenera el ID de sesión preservando los datos (post-login).
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Strict',
                ]
            );
        }
        session_destroy();
        self::$started = false;
    }

    /**
     * Lee y elimina un mensaje flash (one-shot).
     */
    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $msg = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_string($msg) ? $msg : null;
    }
}
