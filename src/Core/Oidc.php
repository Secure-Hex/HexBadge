<?php

declare(strict_types=1);

namespace HexBadge\Core;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use UnexpectedValueException;

/**
 * Pure OpenID Connect checks for Hermes (no I/O): PKCE, ID token, back-channel logout token
 * and role mapping. Every validator throws on any failure and returns the claims otherwise.
 * Ported from Fides (hexcrm-php src/Security/Oidc.php).
 */
final class Oidc
{
    public const LEEWAY_SECONDS = 60;
    public const BACKCHANNEL_EVENT = 'http://schemas.openid.net/event/backchannel-logout';
    /** Maximum logout token age; Hermes issues them with a 2-minute lifetime. */
    private const LOGOUT_TOKEN_MAX_AGE_SECONDS = 600;
    /** Highest first: a person with several roles gets the strongest one. */
    private const ROLES = ['superadmin', 'admin', 'issuer'];

    public static function randomToken(int $bytes = 32): string
    {
        return JWT::urlsafeB64Encode(random_bytes($bytes));
    }

    /** RFC 7636 S256: BASE64URL(SHA256(verifier)). */
    public static function pkceChallenge(string $verifier): string
    {
        return JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
    }

    /** The `kid` named in the token header, or null when the token is not JWT-shaped. */
    public static function tokenKid(string $raw): ?string
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $raw)[0]), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /**
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>
     */
    public static function validateIdToken(string $raw, array $jwks, string $issuer, string $clientId, string $nonce, int $now): array
    {
        [$claims] = self::decode($raw, $jwks, $now);
        self::assertIssuerAndAudience($claims, $issuer, $clientId);

        $aud = $claims['aud'];
        if ((is_array($aud) && count($aud) > 1) || isset($claims['azp'])) {
            if (($claims['azp'] ?? null) !== $clientId) {
                throw new UnexpectedValueException('Invalid azp');
            }
        }

        if (!is_numeric($claims['exp'] ?? null) || !is_numeric($claims['iat'] ?? null)) {
            throw new UnexpectedValueException('Missing exp or iat');
        }

        if ($nonce === '' || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new UnexpectedValueException('Invalid nonce');
        }

        foreach (['sub', 'sid'] as $name) {
            if (!is_string($claims[$name] ?? null) || $claims[$name] === '') {
                throw new UnexpectedValueException('Missing ' . $name);
            }
        }

        // HexBadge only admits logins that went through Hermes' second factor.
        if (($claims['acr'] ?? null) !== 'mfa' || !is_array($claims['amr'] ?? null) || !in_array('otp', $claims['amr'], true)) {
            throw new UnexpectedValueException('MFA required');
        }

        return $claims;
    }

    /**
     * OpenID Connect Back-Channel Logout 1.0, section 2.6. The `jti` replay check needs storage
     * and is done by the caller.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>
     */
    public static function validateLogoutToken(string $raw, array $jwks, string $issuer, string $clientId, int $now): array
    {
        [$claims, $header] = self::decode($raw, $jwks, $now);

        if (($header['typ'] ?? null) !== 'logout+jwt') {
            throw new UnexpectedValueException('Not a logout token');
        }

        self::assertIssuerAndAudience($claims, $issuer, $clientId);

        if (
            !is_numeric($claims['iat'] ?? null)
            || !is_numeric($claims['exp'] ?? null)
            || $now - (int) $claims['iat'] > self::LOGOUT_TOKEN_MAX_AGE_SECONDS
        ) {
            throw new UnexpectedValueException('Invalid iat or exp');
        }

        $events = $claims['events'] ?? null;
        if (!is_array($events) || !is_array($events[self::BACKCHANNEL_EVENT] ?? null)) {
            throw new UnexpectedValueException('Missing back-channel logout event');
        }

        // The nonce is what tells an ID token apart from a logout token.
        if (array_key_exists('nonce', $claims)) {
            throw new UnexpectedValueException('Logout token must not carry a nonce');
        }

        $hasSid = is_string($claims['sid'] ?? null) && $claims['sid'] !== '';
        $hasSub = is_string($claims['sub'] ?? null) && $claims['sub'] !== '';
        if (!$hasSid && !$hasSub) {
            throw new UnexpectedValueException('Missing sid and sub');
        }

        if (!is_string($claims['jti'] ?? null) || $claims['jti'] === '' || strlen($claims['jti']) > 255) {
            throw new UnexpectedValueException('Missing jti');
        }

        return $claims;
    }

    /**
     * Maps the Hermes `roles` claim to a HexBadge role (1:1 keys, highest wins). Unknown keys grant nothing.
     */
    public static function roleFromClaims(mixed $roles): ?string
    {
        if (!is_array($roles)) {
            return null;
        }

        foreach (self::ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The JWKS keys HexBadge will verify with: ES256 P-256 signing keys with a `kid`, whatever else
     * the set offers.
     *
     * @param array<string, mixed> $jwks
     * @return list<array<string, mixed>>
     */
    public static function usableKeys(array $jwks): array
    {
        $keys = [];
        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $key) {
            if (
                is_array($key)
                && ($key['kty'] ?? null) === 'EC'
                && ($key['crv'] ?? null) === 'P-256'
                && ($key['alg'] ?? 'ES256') === 'ES256'
                && ($key['use'] ?? 'sig') === 'sig'
                && is_string($key['kid'] ?? null)
            ) {
                $keys[] = ['alg' => 'ES256'] + $key;
            }
        }

        return $keys;
    }

    /**
     * Verifies signature, exp, iat and nbf with php-jwt, accepting only ES256 P-256 keys whatever
     * the token header or the JWKS say about other algorithms.
     *
     * @param array<string, mixed> $jwks
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function decode(string $raw, array $jwks, int $now): array
    {
        $keys = self::usableKeys($jwks);

        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = $now;
        try {
            $header = new \stdClass(); // php-jwt only fills a non-null $headers argument
            $payload = JWT::decode($raw, JWK::parseKeySet(['keys' => $keys]), $header);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $header = json_decode((string) json_encode($header), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'ES256') {
            throw new UnexpectedValueException('Only ES256 is accepted');
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (!is_array($claims)) {
            throw new UnexpectedValueException('Invalid claims');
        }

        return [$claims, $header];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function assertIssuerAndAudience(array $claims, string $issuer, string $clientId): void
    {
        if ($issuer === '' || ($claims['iss'] ?? null) !== $issuer) {
            throw new UnexpectedValueException('Invalid iss');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if ($clientId === '' || !in_array($clientId, $audiences, true)) {
            throw new UnexpectedValueException('Invalid aud');
        }
    }
}
