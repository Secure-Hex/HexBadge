<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Crypto;
use HexBadge\Core\Database;

/**
 * Lectura/escritura de ajustes editables (tabla settings), con cifrado
 * opcional para valores sensibles (contraseña SMTP).
 */
final class SettingsService
{
    /** @var array<string,string>|null Cache por request. */
    private static ?array $cache = null;

    /** Claves que se almacenan cifradas. */
    private const ENCRYPTED_KEYS = ['smtp_password'];

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        if (!array_key_exists($key, self::$cache)) {
            return $default;
        }
        $value = self::$cache[$key];
        if (in_array($key, self::ENCRYPTED_KEYS, true) && $value !== '') {
            return Crypto::decrypt($value);
        }
        return $value;
    }

    /**
     * @return array<string,string>
     */
    public static function getMany(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = self::get($k);
        }
        return $out;
    }

    public static function set(string $key, string $value): void
    {
        $encrypted = in_array($key, self::ENCRYPTED_KEYS, true);
        $stored    = ($encrypted && $value !== '') ? Crypto::encrypt($value) : $value;

        Database::getInstance()->query(
            'INSERT INTO settings (setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$key, $stored, $encrypted ? 1 : 0]
        );
        self::$cache = null; // invalidar cache
    }

    /**
     * @param array<string,string> $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function isConfigured(string ...$keys): bool
    {
        foreach ($keys as $k) {
            if (self::get($k) === '') {
                return false;
            }
        }
        return true;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            $rows = Database::getInstance()->fetchAll('SELECT setting_key, setting_value FROM settings');
            foreach ($rows as $row) {
                self::$cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (\Throwable) {
            // Tabla aún no existe (instalación previa a esta feature): cache vacío.
            self::$cache = [];
        }
    }
}
