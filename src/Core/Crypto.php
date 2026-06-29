<?php

declare(strict_types=1);

namespace HexBadge\Core;

use RuntimeException;

/**
 * Cifrado simétrico autenticado (AES-256-GCM) para datos sensibles en
 * reposo, como la contraseña SMTP. La clave deriva de APP_SECRET.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $secret = (string) config('app.secret');
        if ($secret === '') {
            throw new RuntimeException('APP_SECRET no configurado; no se puede cifrar.');
        }
        // Derivar una clave de 32 bytes determinística desde el secreto.
        return hash('sha256', 'hexbadge:crypto:' . $secret, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Fallo al cifrar');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }
}
