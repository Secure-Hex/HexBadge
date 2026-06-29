<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * TOTP (RFC 6238) en PHP puro — segundo factor opcional.
 *
 * Algoritmo: HMAC-SHA1, período 30s, 6 dígitos (compatible con Google
 * Authenticator, Authy, 1Password, etc.).
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Genera un secreto aleatorio en Base32 (160 bits).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Código actual para un secreto dado (para pruebas/uso interno).
     */
    public static function code(string $secret, ?int $forTime = null): string
    {
        $time    = $forTime ?? time();
        $counter = (int) floor($time / self::PERIOD);
        return self::hotp($secret, $counter);
    }

    /**
     * Verifica un código permitiendo una ventana de ±$window períodos
     * (tolerancia a desfases de reloj). Comparación en tiempo constante.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $forTime = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $time    = $forTime ?? time();
        $counter = (int) floor($time / self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::hotp($secret, $counter + $i);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * URI otpauth:// para enrolar en la app de autenticación (o generar QR).
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Formatea el secreto en grupos de 4 para mostrarlo legible.
     */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // ---- Internos ----------------------------------------------------

    private static function hotp(string $secret, int $counter): string
    {
        $key    = self::base32Decode($secret);
        $binCtr = pack('N*', 0) . pack('N*', $counter); // contador de 64 bits big-endian
        $hash   = hash_hmac('sha1', $binCtr, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $part   = substr($hash, $offset, 4);
        $value  = (ord($part[0]) & 0x7f) << 24
                | (ord($part[1]) & 0xff) << 16
                | (ord($part[2]) & 0xff) << 8
                | (ord($part[3]) & 0xff);

        $otp = $value % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
