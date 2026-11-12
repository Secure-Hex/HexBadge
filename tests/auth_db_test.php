<?php

declare(strict_types=1);

/**
 * Hermes SSO against the real database: provisioning (link, JIT, conflicts, roles, races),
 * back-channel logout, session revocation, logout, break-glass login and route gating.
 * Drives the real controllers and router; the only seams are a HermesClient with a fake fetch
 * (tokens signed with a test key) and HEXBADGE_ENV_FILE (a copy of .env with test settings).
 *
 * Needs MySQL with the schema: docker exec hexbadge_admin php tests/auth_db_test.php
 * Leaves no data: every row is marked (@hbtest.invalid, hbtest- ids, 192.0.2.x) and deleted at
 * the end; superadmins it deactivates for a moment are restored with their updated_at.
 *
 * Modes (argv[1]): main (default, ADMIN_LOCAL_LOGIN off) runs the others as child processes:
 *   local-login                          the break-glass checks, with ADMIN_LOCAL_LOGIN on
 *   provision <dir> <sub> <email> <at>   one Hermes login, started at unix time <at> (races)
 */

$mode = $argv[1] ?? 'main';

$envFile = (string) tempnam(sys_get_temp_dir(), 'hbtest-env-');
$baseEnv = is_readable(dirname(__DIR__) . '/.env') ? (string) file_get_contents(dirname(__DIR__) . '/.env') : '';
file_put_contents($envFile, $baseEnv . "\n"
    . "HERMES_ISSUER=https://hermes.test.invalid\n"
    . "HERMES_CLIENT_ID=hexbadge-test\n"
    . "HERMES_CLIENT_SECRET=test-secret\n"
    . "HERMES_REDIRECT_URI=https://badge.test.invalid/auth/hermes/callback\n"
    . 'ADMIN_LOCAL_LOGIN=' . ($mode === 'local-login' ? 'true' : 'false') . "\n");
define('HEXBADGE_ENV_FILE', $envFile);

require dirname(__DIR__) . '/src/bootstrap.php';

// The test deactivates real superadmins and deletes audit rows: never against production data.
// The local Docker stack also says APP_ENV=production, so it opts in explicitly.
if (env('APP_ENV', 'production') === 'production' && getenv('HEXBADGE_ALLOW_DB_TEST') !== '1') {
    @unlink($envFile);
    fwrite(STDERR, "Refusing to run: APP_ENV is production. Use a testing database, or set HEXBADGE_ALLOW_DB_TEST=1 on a throwaway local DB.\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', '/dev/null'); // stale-JWKS warnings are expected
restore_exception_handler();
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.save_path', sys_get_temp_dir());
ob_start(); // CLI output would count as sent headers and break session_regenerate_id()
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy(); // no session files left behind
    }
});

use Firebase\JWT\JWT;
use HexBadge\Admin\Controllers\AuthController;
use HexBadge\Admin\Controllers\UserController;
use HexBadge\Core\Auth;
use HexBadge\Core\Database;
use HexBadge\Core\Oidc;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Router;
use HexBadge\Core\View;
use HexBadge\Services\HermesClient;

View::setBasePath(BASE_PATH . '/src/Admin/Views');

const MARK = '@hbtest.invalid';
const PASSWORD = 'Hbtest-Passw0rd!';

$failures = 0;
$passes   = 0;

function check(string $name, bool $condition): void
{
    global $failures, $passes;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . "\n";
    $condition ? $passes++ : $failures++;
}

function db(): Database
{
    return Database::getInstance();
}

function freshSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        Auth::logout(); // also resets Auth's per-request verification
    }
    session_start();
}

/** @param array<string,string> $post @param array<string,string> $get */
function req(string $method, string $uri, array $post = [], array $get = [], string $ip = '192.0.2.10'): Request
{
    return new Request($get, $post, [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'hbtest']);
}

function dispatch(Request $request): Response
{
    $router = new Router();
    require BASE_PATH . '/src/Admin/routes.php';
    return $router->dispatch($request);
}

function location(Response $response): ?string
{
    $headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
    return $headers['Location'] ?? null;
}

/** @param array<string,mixed> $fields */
function makeUser(array $fields): int
{
    return db()->insert('users', $fields + [
        'uuid'          => uuid4(),
        'name'          => 'HB Test',
        'password_hash' => password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        'role'          => 'issuer',
        'is_active'     => 1,
    ]);
}

/** @return array<string,mixed>|null */
function userBy(string $column, string $value): ?array
{
    return db()->fetchOne("SELECT * FROM users WHERE {$column} = ?", [$value]);
}

function auditMark(): int
{
    return (int) db()->fetchColumn('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
}

/** @return array<string,mixed>|null metadata of the newest $action audit row after $mark */
function audited(int $mark, string $action): ?array
{
    $json = db()->fetchColumn('SELECT metadata FROM audit_logs WHERE id > ? AND action = ? ORDER BY id DESC LIMIT 1', [$mark, $action]);
    if ($json === false || $json === null) {
        return null;
    }
    return is_array($decoded = json_decode((string) $json, true)) ? $decoded : [];
}

// ---- Test Hermes: an ES256 key, a cache dir with discovery + JWKS, a fetch that serves tokens ----

/** @return array{string, array<string,mixed>} private PEM and its JWKS */
function testKey(?string $pem = null): array
{
    $key = $pem === null ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) : openssl_pkey_get_private($pem);
    openssl_pkey_export($key, $privatePem);
    $ec = openssl_pkey_get_details($key)['ec'];
    $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => 'test-key',
        'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), 'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))];
    return [$privatePem, ['keys' => [$jwk]]];
}

/** @param array<string,mixed> $jwks */
function cacheDir(array $jwks, int $jwksAge = 0, string $privatePem = ''): string
{
    $dir = sys_get_temp_dir() . '/hbtest-oidc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $issuer = (string) config('hermes.issuer');
    $docs = [
        'discovery' => ['fetched_at' => time(), 'attempted_at' => time(), 'data' => ['issuer' => $issuer, 'authorization_endpoint' => $issuer . '/authorize', 'token_endpoint' => $issuer . '/token', 'jwks_uri' => $issuer . '/jwks']],
        'jwks'      => ['fetched_at' => time() - $jwksAge, 'attempted_at' => time() - $jwksAge, 'data' => $jwks],
    ];
    foreach ($docs as $name => $entry) {
        file_put_contents($dir . '/' . $name . '.json', json_encode($entry));
    }
    file_put_contents($dir . '/key.pem', $privatePem);
    return $dir;
}

function removeDir(string $dir): void
{
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
}

$servedIdToken = '';
$fetchCalls    = 0;
/** Token endpoint answers with $servedIdToken; anything else is "Hermes down". */
$fakeFetch = static function (string $url, ?array $form) use (&$servedIdToken, &$fetchCalls): array {
    $fetchCalls++;
    if ($form !== null) {
        return ['id_token' => $servedIdToken, 'token_type' => 'Bearer'];
    }
    throw new RuntimeException('Hermes down (test)');
};

/** @param array<string,mixed> $claims */
function sign(array $claims, string $pem, string $typ = 'JWT', string $kid = 'test-key'): string
{
    return JWT::encode($claims, $pem, 'ES256', $kid, ['typ' => $typ]);
}

/**
 * One full Hermes login through AuthController::hermesCallback, on a fresh session.
 *
 * @param array<string,mixed> $claims
 */
function hermesLogin(HermesClient $client, string $pem, array $claims): Response
{
    global $servedIdToken;
    freshSession();
    $pending = ['state' => Oidc::randomToken(), 'nonce' => Oidc::randomToken(), 'verifier' => Oidc::randomToken(), 'created_at' => time()];
    $_SESSION['oidc_pending'] = $pending;
    $servedIdToken = sign($claims + [
        'iss' => config('hermes.issuer'), 'aud' => config('hermes.client_id'), 'iat' => time(), 'exp' => time() + 300,
        'nonce' => $pending['nonce'], 'sid' => 'hbtest-sid-' . bin2hex(random_bytes(4)), 'email_verified' => true,
        'name' => 'HB Test', 'roles' => ['issuer'], 'acr' => 'mfa', 'amr' => ['pwd', 'otp'],
    ], $pem);
    return (new AuthController($client))->hermesCallback(req('GET', '/auth/hermes/callback', [], ['state' => $pending['state'], 'code' => 'test-code']));
}

function loggedInTo(Response $response, string $where): bool
{
    return $response->status() === 302 && location($response) === $where;
}

function cleanup(int $auditStart): void
{
    $db = db();
    $db->query('DELETE FROM audit_logs WHERE id > ?', [$auditStart]);
    $db->query("DELETE FROM users WHERE email LIKE '%" . MARK . "'"); // user_companies cascade
    $db->query("DELETE FROM companies WHERE name = 'hbtest company'");
    $db->query("DELETE FROM oidc_backchannel_logouts WHERE jti LIKE 'hbtest-%'");
    $db->query("DELETE FROM rate_limit_attempts WHERE identifier LIKE '192.0.2.%'");
}

// =====================================================================================
// Child: one Hermes login started at a given instant (concurrent first logins)
// =====================================================================================
if ($mode === 'provision') {
    [, , $dir, $sub, $email, $startAt] = $argv;
    $pem = (string) file_get_contents($dir . '/key.pem');
    while (microtime(true) < (float) $startAt) {
        usleep(1000);
    }
    $response = hermesLogin(new HermesClient($fakeFetch, $dir), $pem, ['sub' => $sub, 'email' => $email]);
    echo loggedInTo($response, '/admin') ? 'USER ' . (int) $_SESSION['user_id'] : 'FAIL ' . (string) ($_SESSION['_flash']['error'] ?? 'unknown');
    @unlink($envFile);
    exit(0);
}

// =====================================================================================
// Child: break-glass with ADMIN_LOCAL_LOGIN on
// =====================================================================================
if ($mode === 'local-login') {
    freshSession();
    check('flag on: local-login routes are registered', dispatch(req('GET', '/forgot-password'))->status() === 200);

    $issuer   = makeUser(['email' => 'll-issuer' . MARK, 'role' => 'issuer']);
    $noTotp   = makeUser(['email' => 'll-super-nototp' . MARK, 'role' => 'superadmin']);
    $withTotp = makeUser(['email' => 'll-super-totp' . MARK, 'role' => 'superadmin', 'totp_enabled' => 1, 'totp_secret' => 'JBSWY3DPEHPK3PXP']);

    $cases = [
        ['non-superadmin is refused', 'll-issuer', $issuer, 'not_superadmin', '192.0.2.21'],
        ['superadmin without TOTP is refused (RISK-001)', 'll-super-nototp', $noTotp, 'totp_required', '192.0.2.22'],
    ];
    foreach ($cases as [$name, $local, $id, $reason, $ip]) {
        freshSession();
        $mark = auditMark();
        $response = dispatch(req('POST', '/login', ['email' => $local . MARK, 'password' => PASSWORD], [], $ip));
        $denied = audited($mark, 'user.login.local_denied');
        check('local login: ' . $name, $response->status() === 401 && !isset($_SESSION['user_id']) && !isset($_SESSION['pending_2fa_user_id'])
            && str_contains($response->body(), 'Credenciales inválidas') && ($denied['reason'] ?? null) === $reason);
    }

    freshSession();
    $response = dispatch(req('POST', '/login', ['email' => 'll-super-totp' . MARK, 'password' => PASSWORD], [], '192.0.2.23'));
    check('local login: superadmin with TOTP goes to the 2FA step, no session yet', loggedInTo($response, '/login/2fa')
        && ($_SESSION['pending_2fa_user_id'] ?? null) === $withTotp && !isset($_SESSION['user_id']));

    freshSession();
    Auth::login((array) userBy('id', (string) $withTotp), '192.0.2.24');
    $body = (new UserController())->index(req('GET', '/admin/users'))->body();
    check('flag on: users page shows the invitation form', str_contains($body, 'Invitar usuario'));

    echo "\n{$passes} passed, {$failures} failed.\n";
    @unlink($envFile);
    exit($failures === 0 ? 0 : 1);
}

// =====================================================================================
// Main (ADMIN_LOCAL_LOGIN off)
// =====================================================================================
$auditStart = auditMark();
$restoreSuperadmins = [];
$dirs = [];
register_shutdown_function(static function () use ($auditStart, &$restoreSuperadmins, &$dirs, $envFile): void {
    foreach ($restoreSuperadmins as $row) {
        db()->query('UPDATE users SET is_active = 1, updated_at = ? WHERE id = ?', [$row['updated_at'], $row['id']]);
    }
    cleanup($auditStart);
    array_map('removeDir', $dirs);
    @unlink($envFile);
});
cleanup($auditStart); // leftovers of an aborted run

[$pem, $jwks] = testKey();
$dirs[] = $dir = cacheDir($jwks, 0, $pem);
$client = new HermesClient($fakeFetch, $dir);
$sub = static fn (): string => uuid4();

// ---- Provisioning: link by verified email ----
$u1 = makeUser(['email' => 'link' . MARK, 'role' => 'admin']);
$s1 = $sub();
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s1, 'email' => 'LINK' . MARK, 'roles' => ['admin']]);
check('verified email links an active, never-linked user', loggedInTo($response, '/admin') && ($_SESSION['user_id'] ?? null) === $u1
    && userBy('id', (string) $u1)['hermes_sub'] === $s1 && audited($mark, 'user.hermes.linked') !== null);

$u2 = makeUser(['email' => 'unverified' . MARK]);
$s2 = $sub();
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s2, 'email' => 'unverified' . MARK, 'email_verified' => false]);
check('unverified email never links: email_conflict', loggedInTo($response, '/login') && !isset($_SESSION['user_id'])
    && userBy('id', (string) $u2)['hermes_sub'] === null && userBy('hermes_sub', $s2) === null
    && (audited($mark, 'user.login.failed')['reason'] ?? null) === 'email_conflict');

$u3 = makeUser(['email' => 'taken' . MARK, 'hermes_sub' => $other = $sub()]);
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s3 = $sub(), 'email' => 'taken' . MARK]);
check('email of a user linked to another sub: email_conflict, no relink', loggedInTo($response, '/login')
    && userBy('id', (string) $u3)['hermes_sub'] === $other && userBy('hermes_sub', $s3) === null
    && (audited($mark, 'user.login.failed')['reason'] ?? null) === 'email_conflict');

$u4 = makeUser(['email' => 'inactive-candidate' . MARK, 'is_active' => 0]);
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s4 = $sub(), 'email' => 'inactive-candidate' . MARK]);
check('email of an inactive user: email_conflict, no link', loggedInTo($response, '/login')
    && userBy('id', (string) $u4)['hermes_sub'] === null && userBy('hermes_sub', $s4) === null
    && (audited($mark, 'user.login.failed')['reason'] ?? null) === 'email_conflict');

// ---- JIT provisioning and the awaiting-company page ----
$s5 = $sub();
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s5, 'email' => 'jit' . MARK, 'roles' => ['issuer']]);
$jit = userBy('hermes_sub', $s5);
check('JIT: unknown sub creates an issuer without company or password', loggedInTo($response, '/admin') && $jit !== null
    && $jit['role'] === 'issuer' && $jit['company_id'] === null && $jit['password_hash'] === null
    && audited($mark, 'user.hermes.provisioned') !== null);
check('JIT user without company is parked (front controller redirect condition)', Auth::awaitingCompany());
$awaiting = (new AuthController($client))->awaitingCompany(req('GET', '/awaiting-company'));
check('awaiting-company page renders while no company is assigned', $awaiting->status() === 200 && str_contains($awaiting->body(), 'Esperando asignación de empresa'));
$company = db()->insert('companies', ['uuid' => uuid4(), 'name' => 'hbtest company']);
db()->insert('user_companies', ['user_id' => (int) $jit['id'], 'company_id' => $company]);
$awaiting = (new AuthController($client))->awaitingCompany(req('GET', '/awaiting-company'));
check('awaiting-company sends to /admin once a company is assigned', loggedInTo($awaiting, '/admin') && !Auth::awaitingCompany());

// ---- Claims applied on every login ----
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s5, 'email' => 'jit' . MARK, 'roles' => ['admin']]);
$changed = audited($mark, 'user.role.changed');
check('role is updated from Hermes on login and audited old -> new', loggedInTo($response, '/admin') && userBy('hermes_sub', $s5)['role'] === 'admin'
    && ($changed['from'] ?? null) === 'issuer' && ($changed['to'] ?? null) === 'admin');

hermesLogin($client, $pem, ['sub' => $s5, 'email' => 'jit-unverified' . MARK, 'email_verified' => false, 'roles' => ['admin']]);
check('unverified email does not overwrite users.email (REL-006)', userBy('hermes_sub', $s5)['email'] === 'jit' . MARK);
hermesLogin($client, $pem, ['sub' => $s5, 'email' => 'jit-new' . MARK, 'roles' => ['admin']]);
check('verified email updates users.email', userBy('hermes_sub', $s5)['email'] === 'jit-new' . MARK);

db()->update('users', ['is_active' => 0], 'hermes_sub = ?', [$s5]);
$mark = auditMark();
$response = hermesLogin($client, $pem, ['sub' => $s5, 'email' => 'jit-new' . MARK, 'roles' => ['admin']]);
check('inactive user is refused', loggedInTo($response, '/login') && !isset($_SESSION['user_id'])
    && (audited($mark, 'user.login.failed')['reason'] ?? null) === 'user_inactive');

// ---- Last active superadmin (RES-002) ----
$s6 = $sub();
$super = makeUser(['email' => 'super' . MARK, 'role' => 'superadmin', 'hermes_sub' => $s6]);
$otherSuper = makeUser(['email' => 'super-other' . MARK, 'role' => 'superadmin']);
$mark = auditMark();
hermesLogin($client, $pem, ['sub' => $s6, 'email' => 'super' . MARK, 'roles' => ['admin']]);
check('superadmin is demoted by Hermes while another active superadmin exists', userBy('id', (string) $super)['role'] === 'admin'
    && (audited($mark, 'user.role.changed')['from'] ?? null) === 'superadmin');

db()->update('users', ['role' => 'superadmin'], 'id = ?', [$super]);
$restoreSuperadmins = db()->fetchAll("SELECT id, updated_at FROM users WHERE role = 'superadmin' AND is_active = 1 AND id <> ? AND email NOT LIKE '%" . MARK . "'", [$super]);
foreach ($restoreSuperadmins as $row) {
    db()->query('UPDATE users SET is_active = 0, updated_at = updated_at WHERE id = ?', [$row['id']]);
}
db()->update('users', ['is_active' => 0], 'id = ?', [$otherSuper]);
try {
    $mark = auditMark();
    $response = hermesLogin($client, $pem, ['sub' => $s6, 'email' => 'super' . MARK, 'roles' => ['admin']]);
    check('last active superadmin keeps the role and the block is audited', loggedInTo($response, '/admin')
        && userBy('id', (string) $super)['role'] === 'superadmin' && ($_SESSION['user_role'] ?? null) === 'superadmin'
        && (audited($mark, 'user.hermes.role_demotion_blocked')['claimed_role'] ?? null) === 'admin');
} finally {
    foreach ($restoreSuperadmins as $row) {
        db()->query('UPDATE users SET is_active = 1, updated_at = ? WHERE id = ?', [$row['updated_at'], $row['id']]);
    }
    $restoreSuperadmins = [];
}

// ---- Concurrent first logins of the same person ----
$s7 = $sub();
$startAt = microtime(true) + 1.5;
$children = [];
for ($i = 0; $i < 4; $i++) {
    $children[] = proc_open([PHP_BINARY, __FILE__, 'provision', $dir, $s7, 'race' . MARK, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outputs[$i] = $pipes;
}
$ids = [];
foreach ($children as $i => $process) {
    $out = trim((string) stream_get_contents($outputs[$i][1]) . (string) stream_get_contents($outputs[$i][2]));
    proc_close($process);
    $ids[] = preg_match('/USER (\d+)$/', $out, $m) === 1 ? (int) $m[1] : 0;
    if (!str_starts_with($out, 'USER')) {
        echo "       child said: {$out}\n";
    }
}
$raceCount = (int) db()->fetchColumn('SELECT COUNT(*) FROM users WHERE hermes_sub = ? OR email = ?', [$s7, 'race' . MARK]);
check('4 concurrent first logins all succeed as one user', count(array_unique($ids)) === 1 && $ids[0] > 0 && $raceCount === 1);

// ---- Back-channel logout and per-request revocation ----
$s8 = $sub();
$backUser = makeUser(['email' => 'backchannel' . MARK, 'hermes_sub' => $s8]);
$logoutToken = static fn (array $claims, string $key = '') => sign($claims + [
    'iss' => config('hermes.issuer'), 'aud' => config('hermes.client_id'), 'iat' => time(), 'exp' => time() + 120,
    'jti' => 'hbtest-' . bin2hex(random_bytes(6)), 'events' => [Oidc::BACKCHANNEL_EVENT => new stdClass()],
], $key !== '' ? $key : $pem, 'logout+jwt');
$backchannel = static fn (string $token, ?HermesClient $with = null): Response => (new AuthController($with ?? $client))
    ->backchannelLogout(req('POST', '/auth/hermes/backchannel-logout', $token === '' ? [] : ['logout_token' => $token]));
/** Auth::check() for a Hermes session of $backUser, as a new request would run it. */
$sessionAlive = static function (string $sid, int $loggedInAt) use ($backUser, $s8): bool {
    freshSession();
    $_SESSION = ['user_id' => $backUser, 'user_role' => 'issuer', 'auth_at' => time(),
        'oidc' => ['sub' => $s8, 'sid' => $sid, 'logged_in_at' => date('Y-m-d H:i:s', $loggedInAt)]];
    return Auth::check();
};

check('Hermes session is alive before any back-channel logout', $sessionAlive('hbtest-sid-a', time() - 60));
$token = $logoutToken(['sid' => 'hbtest-sid-a', 'sub' => $s8]);
check('back-channel logout with a valid token answers 200', $backchannel($token)->status() === 200);
check('the session of that sid is revoked (Auth::sessionState)', !$sessionAlive('hbtest-sid-a', time() - 60)
    && ($_SESSION['_flash']['error'] ?? null) === 'Tu sesión fue cerrada desde Hermes.');
check('another sid of the same sub stays alive', $sessionAlive('hbtest-sid-b', time() - 60));
check('replayed token (duplicate jti) answers 200 and records once', $backchannel($token)->status() === 200
    && (int) db()->fetchColumn('SELECT COUNT(*) FROM oidc_backchannel_logouts WHERE sid = ?', ['hbtest-sid-a']) === 1);

check('sub-wide logout (no sid) answers 200', $backchannel($logoutToken(['sub' => $s8]))->status() === 200);
check('sub-wide logout revokes sessions opened before it', !$sessionAlive('hbtest-sid-b', time() - 60));
check('sub-wide logout spares sessions opened after it', $sessionAlive('hbtest-sid-c', time() + 60));

[$otherPem] = testKey();
check('back-channel token signed by another key answers 400', $backchannel($logoutToken(['sid' => 'hbtest-sid-x'], $otherPem))->status() === 400);
check('garbage back-channel token answers 400', $backchannel('not.a.jwt')->status() === 400);
check('missing back-channel token answers 400', $backchannel('')->status() === 400);

$dirs[] = $staleDir = cacheDir($jwks, 100000);
$fetchCalls = 0;
$response = $backchannel($logoutToken(['sid' => 'hbtest-sid-d']), new HermesClient($fakeFetch, $staleDir));
check('known kid with a failing JWKS refresh is served stale: 200, not 5xx', $response->status() === 200 && $fetchCalls === 1);
$dirs[] = $emptyDir = sys_get_temp_dir() . '/hbtest-oidc-empty-' . bin2hex(random_bytes(4));
check('no JWKS at all and Hermes down answers 503 (Hermes retries)', $backchannel($logoutToken(['sid' => 'hbtest-sid-e']), new HermesClient($fakeFetch, $emptyDir))->status() === 503);

// ---- Logout (REL-005) ----
$mark = auditMark();
$sessionAlive('hbtest-sid-f', time() + 60); // opened after the sub-wide logout above
$response = (new AuthController($client))->logout(req('POST', '/logout'));
check('logout without CSRF still destroys the session', loggedInTo($response, '/login') && !isset($_SESSION['user_id'])
    && (audited($mark, 'user.logout')['csrf'] ?? null) === 'invalid');

// ---- Route gating with ADMIN_LOCAL_LOGIN off ----
freshSession();
$gated = [['GET', '/forgot-password'], ['POST', '/forgot-password'], ['GET', '/reset-password/abc'], ['POST', '/reset-password/abc'],
    ['GET', '/login/2fa'], ['POST', '/login/2fa'], ['POST', '/login'], ['GET', '/accept-invite/abc'], ['POST', '/accept-invite/abc'], ['POST', '/admin/users']];
$statuses = array_map(static fn (array $r): int => dispatch(req($r[0], $r[1]))->status(), $gated);
check('flag off: forgot/reset/2fa/login/accept-invite/invite answer 404', array_unique($statuses) === [404]);
$loginPage = dispatch(req('GET', '/login'));
check('flag off: /login shows Hermes only', $loginPage->status() === 200 && str_contains($loginPage->body(), 'Entrar con Hermes')
    && !str_contains($loginPage->body(), 'Ingresar con contraseña'));

freshSession();
Auth::login((array) userBy('id', (string) $super), '192.0.2.30', ['sub' => $s6, 'sid' => 'hbtest-sid-g']);
$body = (new UserController())->index(req('GET', '/admin/users'))->body();
check('flag off: users page hides the invitation form and table', !str_contains($body, 'Invitar usuario') && !str_contains($body, '<h2>Invitaciones</h2>'));

// ---- Break-glass, in a child process with ADMIN_LOCAL_LOGIN on ----
$child = proc_open([PHP_BINARY, __FILE__, 'local-login'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
proc_close($child);
foreach (explode("\n", $out) as $line) {
    if (preg_match('/^\[(PASS|FAIL)\] (.*)$/', $line, $m) === 1) {
        check($m[2], $m[1] === 'PASS');
    } elseif (trim($line) !== '' && !str_contains($line, 'passed,')) {
        echo "       child: {$line}\n";
    }
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
