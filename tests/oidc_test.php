<?php

declare(strict_types=1);

/**
 * Hermes OpenID Connect: PKCE, ID token, logout token, role mapping, the per-request session
 * decision, duplicate-key classification and HermesClient caching.
 * Runs without a database or network: php tests/oidc_test.php
 * Ported from Fides (hexcrm-php tests/oidc_test.php).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
restore_exception_handler();

use Firebase\JWT\JWT;
use HexBadge\Core\Auth;
use HexBadge\Core\Oidc;
use HexBadge\Models\User;
use HexBadge\Services\HermesClient;

$failures = 0;
$passes   = 0;

function check(string $name, bool $condition): void
{
    global $failures, $passes;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . "\n";
    $condition ? $passes++ : $failures++;
}

/** True only when $validate throws for the expected reason: a fragment of "Class: message". */
function rejects(callable $validate, string $reason): bool
{
    try {
        $validate();
        return false;
    } catch (Throwable $exception) {
        $seen = $exception::class . ': ' . $exception->getMessage();
        if (!str_contains($seen, $reason)) {
            echo "       rejected for another reason: {$seen}\n";
            return false;
        }
        return true;
    }
}

const ISSUER = 'https://hermes.securehex.cl';
const CLIENT_ID = 'hexbadge-client';
const KID = 'key-1';
const NONCE = 'nonce-123';
const HMAC_SECRET = 'a-32-byte-shared-secret-for-hs256'; // php-jwt refuses shorter HS256 keys

$now = 1_800_000_000;
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($key, $privatePem);
$publicPem = openssl_pkey_get_details($key)['key'];
$ec = openssl_pkey_get_details($key)['ec'];
$jwk = ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => KID, 'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), 'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))];
$jwks = ['keys' => [$jwk, ['kty' => 'oct', 'alg' => 'HS256', 'kid' => 'hmac', 'k' => JWT::urlsafeB64Encode(HMAC_SECRET)]]];

$idClaims = [
    'iss' => ISSUER, 'sub' => '0b9f7a52-1c1e-4c39-9d2a-9f0e3c2d1a11', 'aud' => CLIENT_ID, 'exp' => $now + 300, 'iat' => $now,
    'nonce' => NONCE, 'sid' => 'sid-1', 'email' => 'a@securehex.cl', 'email_verified' => true, 'roles' => ['admin'],
    'acr' => 'mfa', 'amr' => ['pwd', 'otp'],
];
$sign = static fn (array $claims, string $typ = 'JWT', string $kid = KID): string => JWT::encode($claims, $privatePem, 'ES256', $kid, ['typ' => $typ]);
$validId = static fn (string $token, ?array $set = null): array => Oidc::validateIdToken($token, $set ?? $jwks, ISSUER, CLIENT_ID, NONCE, $now);

// ID token
$claims = $validId($sign($idClaims));
check('valid ID token is accepted', $claims['sub'] === $idClaims['sub'] && $claims['roles'] === ['admin']);
check('ID token with wrong iss is rejected', rejects(fn () => $validId($sign(['iss' => 'https://evil.example'] + $idClaims)), 'Invalid iss'));
check('ID token with wrong aud is rejected', rejects(fn () => $validId($sign(['aud' => 'other'] + $idClaims)), 'Invalid aud'));
check('ID token with aud list and no azp is rejected', rejects(fn () => $validId($sign(['aud' => [CLIENT_ID, 'other']] + $idClaims)), 'Invalid azp'));
check('ID token with a foreign azp is rejected', rejects(fn () => $validId($sign(['azp' => 'other'] + $idClaims)), 'Invalid azp'));
check('ID token with wrong nonce is rejected', rejects(fn () => $validId($sign(['nonce' => 'other'] + $idClaims)), 'Invalid nonce'));
check('ID token without nonce is rejected', rejects(fn () => $validId($sign(array_diff_key($idClaims, ['nonce' => 1]))), 'Invalid nonce'));
check('ID token expired beyond leeway is rejected', rejects(fn () => $validId($sign(['exp' => $now - Oidc::LEEWAY_SECONDS - 1] + $idClaims)), 'ExpiredException'));
check('ID token expired within leeway is accepted', $validId($sign(['exp' => $now - 30] + $idClaims))['sub'] !== '');
check('ID token issued in the future is rejected', rejects(fn () => $validId($sign(['iat' => $now + 600] + $idClaims)), 'BeforeValidException'));
check('ID token without sid is rejected', rejects(fn () => $validId($sign(array_diff_key($idClaims, ['sid' => 1]))), 'Missing sid'));
check('ID token without sub is rejected', rejects(fn () => $validId($sign(array_diff_key($idClaims, ['sub' => 1]))), 'Missing sub'));
check('ID token without exp is rejected', rejects(fn () => $validId($sign(array_diff_key($idClaims, ['exp' => 1]))), 'Missing exp or iat'));
check('ID token without amr otp is rejected', rejects(fn () => $validId($sign(['amr' => ['pwd']] + $idClaims)), 'MFA required'));
check('ID token without amr is rejected', rejects(fn () => $validId($sign(array_diff_key($idClaims, ['amr' => 1]))), 'MFA required'));
check('ID token with acr other than mfa is rejected', rejects(fn () => $validId($sign(['acr' => 'pwd'] + $idClaims)), 'MFA required'));
check('ID token signed with HS256 is rejected', rejects(fn () => $validId(JWT::encode($idClaims, HMAC_SECRET, 'HS256', 'hmac')), '"kid" invalid'));
// Algorithm confusion: HS256 keyed with the EC public key material, naming the EC key's own kid.
check('ID token signed with HS256 under the EC kid is rejected', rejects(fn () => $validId(JWT::encode($idClaims, $publicPem, 'HS256', KID)), 'Incorrect key for this algorithm'));
$header = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'kid' => KID]));
check('ID token with alg none is rejected', rejects(fn () => $validId($header . '.' . JWT::urlsafeB64Encode((string) json_encode($idClaims)) . '.'), 'Algorithm not supported'));
check('ID token with unknown kid is rejected', rejects(fn () => $validId($sign($idClaims, 'JWT', 'unknown')), '"kid" invalid'));
check('JWKS without a usable EC key rejects every token', rejects(fn () => $validId($sign($idClaims), ['keys' => [$jwks['keys'][1], ['kty' => 'EC', 'crv' => 'P-384', 'kid' => KID]]]), 'JWK Set did not contain any keys'));
check('ID token signed by another key is rejected', rejects(function () use ($idClaims, $validId): void {
    openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $otherPem);
    $validId(JWT::encode($idClaims, $otherPem, 'ES256', KID));
}, 'SignatureInvalidException'));
check('tokenKid reads the header kid', Oidc::tokenKid($sign($idClaims, 'JWT', 'rotated')) === 'rotated' && Oidc::tokenKid('garbage') === null);

// Logout token
$logoutClaims = [
    'iss' => ISSUER, 'aud' => CLIENT_ID, 'iat' => $now, 'exp' => $now + 120, 'jti' => 'jti-1', 'sid' => 'sid-1', 'sub' => $idClaims['sub'],
    'events' => [Oidc::BACKCHANNEL_EVENT => new stdClass()],
];
$validLogout = static fn (string $token): array => Oidc::validateLogoutToken($token, $jwks, ISSUER, CLIENT_ID, $now);
check('valid logout token is accepted', $validLogout($sign($logoutClaims, 'logout+jwt'))['jti'] === 'jti-1');
check('logout token with only sub is accepted', $validLogout($sign(array_diff_key($logoutClaims, ['sid' => 1]), 'logout+jwt'))['sub'] === $idClaims['sub']);
check('logout token with only sid is accepted', $validLogout($sign(array_diff_key($logoutClaims, ['sub' => 1]), 'logout+jwt'))['sid'] === 'sid-1');
check('logout token with nonce is rejected', rejects(fn () => $validLogout($sign(['nonce' => NONCE] + $logoutClaims, 'logout+jwt')), 'must not carry a nonce'));
check('logout token without events is rejected', rejects(fn () => $validLogout($sign(array_diff_key($logoutClaims, ['events' => 1]), 'logout+jwt')), 'Missing back-channel logout event'));
check('logout token with another event is rejected', rejects(fn () => $validLogout($sign(['events' => ['urn:other' => new stdClass()]] + $logoutClaims, 'logout+jwt')), 'Missing back-channel logout event'));
check('logout token with wrong typ is rejected', rejects(fn () => $validLogout($sign($logoutClaims, 'JWT')), 'Not a logout token'));
check('logout token without sid and sub is rejected', rejects(fn () => $validLogout($sign(array_diff_key($logoutClaims, ['sid' => 1, 'sub' => 1]), 'logout+jwt')), 'Missing sid and sub'));
check('logout token without jti is rejected', rejects(fn () => $validLogout($sign(array_diff_key($logoutClaims, ['jti' => 1]), 'logout+jwt')), 'Missing jti'));
check('logout token with a jti over 255 bytes is rejected', rejects(fn () => $validLogout($sign(['jti' => str_repeat('j', 256)] + $logoutClaims, 'logout+jwt')), 'Missing jti'));
check('logout token without iat is rejected', rejects(fn () => $validLogout($sign(array_diff_key($logoutClaims, ['iat' => 1]), 'logout+jwt')), 'Invalid iat or exp'));
check('logout token without exp is rejected', rejects(fn () => $validLogout($sign(array_diff_key($logoutClaims, ['exp' => 1]), 'logout+jwt')), 'Invalid iat or exp'));
check('logout token issued too long ago is rejected', rejects(fn () => $validLogout($sign(['iat' => $now - 900, 'exp' => $now + 60] + $logoutClaims, 'logout+jwt')), 'Invalid iat or exp'));
check('expired logout token is rejected', rejects(fn () => $validLogout($sign(['exp' => $now - 120] + $logoutClaims, 'logout+jwt')), 'ExpiredException'));
check('logout token with wrong aud is rejected', rejects(fn () => $validLogout($sign(['aud' => 'other'] + $logoutClaims, 'logout+jwt')), 'Invalid aud'));
check('logout token with wrong iss is rejected', rejects(fn () => $validLogout($sign(['iss' => 'https://evil.example'] + $logoutClaims, 'logout+jwt')), 'Invalid iss'));
check('logout token signed with HS256 is rejected', rejects(fn () => Oidc::validateLogoutToken(JWT::encode($logoutClaims, HMAC_SECRET, 'HS256', 'hmac', ['typ' => 'logout+jwt']), $jwks, ISSUER, CLIENT_ID, $now), '"kid" invalid'));
check('ID token is not accepted as a logout token', rejects(fn () => $validLogout($sign($idClaims, 'logout+jwt')), 'Missing back-channel logout event'));

// PKCE (RFC 7636 Appendix B) and random values
check('PKCE S256 matches RFC 7636 Appendix B', Oidc::pkceChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk') === 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
$verifier = Oidc::randomToken();
check('PKCE verifier is 43 unreserved characters', preg_match('/^[A-Za-z0-9_-]{43}$/', $verifier) === 1 && $verifier !== Oidc::randomToken());

// Role mapping: Hermes keys 1:1, highest wins, unknown grants nothing
check('roles superadmin wins over admin and issuer', Oidc::roleFromClaims(['issuer', 'superadmin', 'admin']) === 'superadmin');
check('roles admin wins over issuer', Oidc::roleFromClaims(['issuer', 'admin']) === 'admin');
check('roles issuer maps to issuer', Oidc::roleFromClaims(['issuer']) === 'issuer');
check('unknown, empty, missing or non-list roles map to nothing', Oidc::roleFromClaims(['root']) === null && Oidc::roleFromClaims([]) === null && Oidc::roleFromClaims(null) === null && Oidc::roleFromClaims('admin') === null);

// Per-request session decision (Auth::sessionDecision)
$session = ['user_id' => 7, 'auth_at' => $now - 60, 'oidc' => ['sub' => 'sub-7', 'sid' => 'sid-7', 'logged_in_at' => '2027-01-15 10:00:00']];
$live = ['is_active' => 1, 'revoked' => 0];
$decide = static fn (array $overrides = [], ?array $state = ['is_active' => 1, 'revoked' => 0], bool $local = false): string => Auth::sessionDecision($overrides + $session, $now, $state, $local);
check('active Hermes session is kept', $decide() === Auth::SESSION_OK);
check('session past the 12 h absolute lifetime ends', $decide(['auth_at' => $now - Auth::ABSOLUTE_LIFETIME_SECONDS - 1]) === Auth::SESSION_EXPIRED);
check('session within the 12 h absolute lifetime is kept', $decide(['auth_at' => $now - Auth::ABSOLUTE_LIFETIME_SECONDS + 10]) === Auth::SESSION_OK);
check('session without a login time ends', $decide(['auth_at' => null]) === Auth::SESSION_EXPIRED);
check('session revoked by a back-channel logout ends', $decide([], ['is_active' => 1, 'revoked' => 1]) === Auth::SESSION_REVOKED);
check('session of a deactivated user ends', $decide([], ['is_active' => 0, 'revoked' => 0]) === Auth::SESSION_INACTIVE);
check('session of a deleted user ends', $decide([], null) === Auth::SESSION_INACTIVE);
check('local session ends when ADMIN_LOCAL_LOGIN is off', $decide(['oidc' => null], $live, false) === Auth::SESSION_LOCAL_DISABLED);
check('local session is kept when ADMIN_LOCAL_LOGIN is on', $decide(['oidc' => null], $live, true) === Auth::SESSION_OK);
check('local session of a deactivated user ends', $decide(['oidc' => null], ['is_active' => 0, 'revoked' => 0], true) === Auth::SESSION_INACTIVE);

// Provisioning conflict classification (User::duplicateKey)
check('duplicate hermes_sub is told apart', User::duplicateKey(['23000', 1062, "Duplicate entry '0b9f7a52' for key 'users.uq_users_hermes_sub'"]) === 'hermes_sub');
check('duplicate email is told apart (MySQL 8 and MariaDB key names)', User::duplicateKey(['23000', 1062, "Duplicate entry 'a@b.cl' for key 'users.email'"]) === 'email' && User::duplicateKey(['23000', 1062, "Duplicate entry 'a@b.cl' for key 'email'"]) === 'email');
check('other integrity errors are not a duplicate key', User::duplicateKey(['23000', 1452, 'Cannot add or update a child row']) === null && User::duplicateKey(['23000', 1062, "Duplicate entry 'x' for key 'PRIMARY'"]) === null && User::duplicateKey(null) === null);

// HermesClient: cache, stale-if-error and fetch throttle, with an injected fetch and no network
ini_set('error_log', '/dev/null'); // stale-copy warnings are expected here
$issuer = (new HermesClient(static fn () => []))->config()['issuer'];
$discoveryDoc = ['issuer' => $issuer, 'authorization_endpoint' => 'https://h/a', 'token_endpoint' => 'https://h/t', 'jwks_uri' => 'https://h/jwks'];
$tmpDirs = [];
$cacheDir = static function (array $entries = []) use (&$tmpDirs): string {
    $dir = sys_get_temp_dir() . '/hexbadge-oidc-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $tmpDirs[] = $dir;
    foreach ($entries as $name => $entry) {
        file_put_contents($dir . '/' . $name . '.json', json_encode($entry));
    }
    return $dir;
};
$calls = 0;
$failing = static function () use (&$calls): array {
    $calls++;
    throw new RuntimeException('Hermes down');
};
$freshDiscovery = ['fetched_at' => time(), 'attempted_at' => time(), 'data' => $discoveryDoc];
$staleJwks = ['fetched_at' => time() - 1000, 'attempted_at' => time() - 1000, 'data' => $jwks];
$token = $sign($idClaims);

$calls = 0;
$client = new HermesClient($failing, $cacheDir(['discovery' => $freshDiscovery, 'jwks' => $staleJwks]));
check('stale JWKS is served when the refresh fails (known kid keeps verifying)', $client->jwksFor($token) === $jwks && $calls === 1);
check('a failed refresh is not retried within 60 s', $client->jwksFor($token) === $jwks && $calls === 1);

$calls = 0;
$client = new HermesClient($failing, $cacheDir());
check('no cache and Hermes down throws', rejects(fn () => $client->discovery(), 'Hermes down') && $calls === 1);
check('no cache and Hermes down is throttled too', rejects(fn () => $client->discovery(), 'retry throttled') && $calls === 1);

$rotatedJwks = ['keys' => [['kid' => 'rotated'] + $jwk]];
$rotating = static function () use (&$calls, $rotatedJwks): array {
    $calls++;
    return $rotatedJwks;
};
$calls = 0;
$recentJwks = ['fetched_at' => time() - 100, 'attempted_at' => time() - 100, 'data' => $jwks];
$client = new HermesClient($rotating, $cacheDir(['discovery' => $freshDiscovery, 'jwks' => $recentJwks]));
check('fresh JWKS is served from cache for a known kid', $client->jwksFor($token) === $jwks && $calls === 0);
check('unknown kid refetches the JWKS', $client->jwksFor($sign($idClaims, 'JWT', 'rotated')) === $rotatedJwks && $calls === 1);
check('unknown kid refetch is throttled to one per 60 s', $client->jwksFor($sign($idClaims, 'JWT', 'other')) === $rotatedJwks && $calls === 1);
check('a throttled unknown kid is still rejected', rejects(fn () => $validId($sign($idClaims, 'JWT', 'other'), $client->jwksFor($sign($idClaims, 'JWT', 'other'))), '"kid" invalid'));

$calls = 0;
$client = new HermesClient($failing, $cacheDir(['discovery' => $freshDiscovery, 'jwks' => $recentJwks]));
check('Hermes down: unknown kid gets the cached set and is rejected', rejects(fn () => $validId($sign($idClaims, 'JWT', 'evil'), $client->jwksFor($sign($idClaims, 'JWT', 'evil'))), '"kid" invalid') && $calls === 1);

foreach ($tmpDirs as $dir) {
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
