<?php

declare(strict_types=1);

/**
 * MCP server (POST /mcp) against the real database, plus an API v1 regression pass over the
 * shared ApiOperations: JSON-RPC handling, Bearer auth, scopes, Origin check, HTTP methods.
 * Drives the real router and controllers; the request body is injected through Request.
 *
 * Needs MySQL with the schema: docker exec -e HEXBADGE_ALLOW_DB_TEST=1 hexbadge_admin php tests/mcp_test.php
 * Only touches marked test data: users and earners @hbmcp.invalid, companies and templates named
 * "HB MCP Test ...", their badges, API keys, audit and rate-limit rows and spooled mails; all
 * deleted at the end. The test company's SMTP points at a closed local port, so no real email
 * leaves: the issuance mail fails and is spooled to storage/mail (then removed).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

if (env('APP_ENV', 'production') === 'production' && getenv('HEXBADGE_ALLOW_DB_TEST') !== '1') {
    fwrite(STDERR, "Refusing to run: APP_ENV is production. Use a testing database, or set HEXBADGE_ALLOW_DB_TEST=1 on a throwaway local DB.\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
restore_exception_handler();

use HexBadge\Core\Database;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Router;
use HexBadge\Services\ApiKeyService;

const MARK  = '@hbmcp.invalid';
const TNAME = 'HB MCP Test';

$failures = 0;
$passes   = 0;

function check(string $name, bool $condition): void
{
    global $failures, $passes;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . "\n";
    $condition ? $passes++ : $failures++;
}

/** @param array<string,string> $headers */
function send(string $method, string $uri, ?string $body = null, array $headers = []): Response
{
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '192.0.2.50', 'HTTP_USER_AGENT' => 'hbmcp'];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $router = new Router();
    require BASE_PATH . '/src/Admin/routes.php';
    return $router->dispatch(new Request([], [], [], $server, $body));
}

/** @param array<string,mixed> $msg */
function rpc(string $key, array $msg, array $headers = []): Response
{
    return send('POST', '/mcp', json_encode($msg), $headers + ['Authorization' => 'Bearer ' . $key]);
}

/** @return array<string,mixed> */
function body(Response $r): array
{
    return json_decode($r->body(), true) ?? [];
}

function header_of(Response $r, string $name): ?string
{
    return (new ReflectionProperty(Response::class, 'headers'))->getValue($r)[$name] ?? null;
}

$db = Database::getInstance();

function cleanup(Database $db): void
{
    $ids = array_column($db->fetchAll(
        "SELECT k.id FROM api_keys k JOIN users u ON u.id = k.user_id WHERE u.email LIKE '%" . MARK . "'"
    ), 'id');
    foreach ($ids as $id) {
        $db->query('DELETE FROM audit_logs WHERE api_key_id = ?', [$id]);
        $db->query('DELETE FROM rate_limit_attempts WHERE identifier = ?', ['apikey:' . $id]);
    }
    $earners = "SELECT id FROM earners WHERE email LIKE '%" . MARK . "'";
    $tpls    = "SELECT id FROM badge_templates WHERE name LIKE '" . TNAME . "%'";
    $users   = "SELECT id FROM users WHERE email LIKE '%" . MARK . "'";
    $cos     = "SELECT id FROM companies WHERE name LIKE '" . TNAME . "%'";
    $db->query("DELETE FROM issued_badges WHERE earner_id IN ({$earners}) OR badge_template_id IN ({$tpls})");
    $db->query("DELETE FROM earner_emails WHERE earner_id IN ({$earners})");
    $db->query("DELETE FROM earners WHERE email LIKE '%" . MARK . "'");
    $db->query("DELETE FROM badge_templates WHERE name LIKE '" . TNAME . "%'");
    $db->query("DELETE FROM audit_logs WHERE user_id IN ({$users}) OR company_id IN ({$cos})");
    $db->query("DELETE FROM users WHERE email LIKE '%" . MARK . "'"); // api_keys cascade
    $db->query("DELETE FROM companies WHERE name LIKE '" . TNAME . "%'");
    foreach (glob(BASE_PATH . '/storage/mail/*' . MARK . '*') ?: [] as $file) {
        unlink($file);
    }
}

cleanup($db); // leftovers of an aborted run

$userId = $db->insert('users', [
    'uuid'          => uuid4(),
    'email'         => 'owner' . MARK,
    'name'          => 'HB MCP Test',
    'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4]),
    'role'          => 'issuer',
    'is_active'     => 1,
]);
$keys = new ApiKeyService();
$read  = $keys->generate($userId, 'hbmcp read', ['templates:read'])['key'];
$issue = $keys->generate($userId, 'hbmcp issue', ['badges:issue'])['key'];
$globalRead = $keys->generate($userId, 'hbmcp global read', ['badges:read'])['key'];

try {
    // Two test companies. A's SMTP is a closed local port: the issuance mail fails fast, never leaves.
    $company = static fn (string $suffix, array $extra = []): int => $db->insert('companies', ['uuid' => uuid4(), 'name' => TNAME . ' ' . $suffix] + $extra);
    $coA = $company('A', ['smtp_host' => '127.0.0.1', 'smtp_port' => 9, 'smtp_encryption' => 'none']);
    $coB = $company('B');
    $userA = $db->insert('users', [
        'uuid'          => uuid4(),
        'email'         => 'owner-a' . MARK,
        'name'          => 'HB MCP Test A',
        'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4]),
        'role'          => 'issuer',
        'is_active'     => 1,
        'company_id'    => $coA,
    ]);
    $keyA = $keys->generate($userA, 'hbmcp company A', ['badges:read', 'badges:issue', 'bulk:issue'])['key'];
    $template = static fn (int $companyId, string $suffix): array => $db->fetchOne('SELECT * FROM badge_templates WHERE id = ?', [$db->insert('badge_templates', [
        'uuid' => uuid4(), 'created_by' => $userId, 'company_id' => $companyId, 'name' => TNAME . ' template ' . $suffix,
        'description' => 'test', 'criteria_text' => 'test', 'image_filename' => 'hbmcp-test.svg', 'state' => 'active', 'is_active' => 1,
    ])]);
    $tplA = $template($coA, 'A');
    $tplB = $template($coB, 'B');
    

    // --- initialize ---------------------------------------------------------------
    $r = body(rpc($read, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]));
    check('initialize echoes a supported protocol version', ($r['result']['protocolVersion'] ?? null) === '2025-03-26');
    check('initialize announces tools and serverInfo', isset($r['result']['capabilities']['tools']) && ($r['result']['serverInfo']['name'] ?? null) === 'hexbadge' && ($r['result']['serverInfo']['version'] ?? '') === hexbadge_version());
    $r = body(rpc($read, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]));
    check('initialize falls back to the latest version', ($r['result']['protocolVersion'] ?? null) === '2025-06-18');

    // --- notifications ------------------------------------------------------------
    $resp = rpc($read, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    check('notification answers 202 with an empty body', $resp->status() === 202 && $resp->body() === '');

    // --- ping / tools/list --------------------------------------------------------
    check('ping returns an empty object', rpc($read, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'])->body() === '{"jsonrpc":"2.0","id":3,"result":{}}');
    $names = static fn (string $key): array => array_column(body(rpc($key, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list']))['result']['tools'] ?? [], 'name');
    check('tools/list for templates:read', $names($read) === ['list_templates', 'get_template']);
    check('tools/list for badges:issue', $names($issue) === ['issue_badge', 'revoke_badge']);
    $tools = array_column(body(rpc($issue, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']))['result']['tools'], null, 'name');
    check('revoke_badge is annotated destructive', ($tools['revoke_badge']['annotations']['destructiveHint'] ?? null) === true);
    check('issue_badge schema uses the REST param names', ($tools['issue_badge']['inputSchema']['required'] ?? null) === ['template_id', 'email', 'first_name', 'last_name']);

    // --- tools/call ---------------------------------------------------------------
    $call = static fn (string $key, string $tool, array $args = []): array => body(rpc($key, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]]))['result'] ?? [];
    $r = $call($read, 'list_templates');
    check('list_templates succeeds with structured content', !isset($r['isError']) && is_array($r['structuredContent']['items'] ?? null));
    check('list_templates text is the JSON of the structured content', json_decode($r['content'][0]['text'] ?? 'null', true) === $r['structuredContent']);
    $r = $call($read, 'get_template', ['uuid' => uuid4()]);
    check('get_template on an unknown uuid is a tool error', ($r['isError'] ?? false) === true && ($r['content'][0]['text'] ?? '') === 'Template no encontrado');
    $r = $call($read, 'get_template', ['uuid' => ['not', 'a', 'string']]);
    check('wrongly typed argument is a tool error', ($r['isError'] ?? false) === true);
    $r = $call($read, 'revoke_badge', ['uuid' => uuid4()]);
    check('call without the scope is a tool error', ($r['isError'] ?? false) === true && ($r['content'][0]['text'] ?? '') === 'Scope no autorizado');
    $r = body(rpc($read, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'nope']]));
    check('unknown tool is -32602', ($r['error']['code'] ?? null) === -32602);
    $resp = rpc($read, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => ['list_templates']]]);
    check('non-string tool name is -32602 with HTTP 200', $resp->status() === 200 && (body($resp)['error']['code'] ?? null) === -32602);
    $resp = rpc($read, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 5]]);
    check('numeric tool name is -32602 with HTTP 200', $resp->status() === 200 && (body($resp)['error']['code'] ?? null) === -32602);

    // --- company isolation on earner lookup (RISK-001) ----------------------------
    $earnerB = (int) \HexBadge\Models\Earner::findOrCreate('only-b' . MARK, 'Only', 'B')['id'];
    $db->insert('issued_badges', ['uuid' => uuid4(), 'badge_template_id' => (int) $tplB['id'], 'earner_id' => $earnerB, 'issued_by' => $userId, 'issued_via' => 'api', 'status' => 'accepted']);
    $unknown = body(send('GET', '/api/v1/earners/' . rawurlencode('nobody' . MARK) . '/badges', null, ['Authorization' => 'Bearer ' . $keyA]));
    $resp = send('GET', '/api/v1/earners/' . rawurlencode('only-b' . MARK) . '/badges', null, ['Authorization' => 'Bearer ' . $keyA]);
    check('REST company key: earner with badges only elsewhere is 404 like an unknown email', $resp->status() === 404 && body($resp)['error'] === $unknown['error'] && !str_contains($resp->body(), 'only-b'));
    $r = $call($keyA, 'list_earner_badges', ['email' => 'only-b' . MARK]);
    check('MCP company key: earner with badges only elsewhere is not found', ($r['isError'] ?? false) === true && ($r['content'][0]['text'] ?? '') === 'Earner no encontrado');
    $resp = send('GET', '/api/v1/earners/' . rawurlencode('only-b' . MARK) . '/badges', null, ['Authorization' => 'Bearer ' . $globalRead]);
    check('REST global key still sees that earner', $resp->status() === 200 && count(body($resp)['data']['badges'] ?? []) === 1);
    $db->insert('issued_badges', ['uuid' => uuid4(), 'badge_template_id' => (int) $tplA['id'], 'earner_id' => $earnerB, 'issued_by' => $userId, 'issued_via' => 'api', 'status' => 'accepted']);
    $resp = send('GET', '/api/v1/earners/' . rawurlencode('only-b' . MARK) . '/badges', null, ['Authorization' => 'Bearer ' . $keyA]);
    check('REST company key sees only its own badge once it has one', $resp->status() === 200 && count(body($resp)['data']['badges'] ?? []) === 1 && body($resp)['data']['badges'][0]['name'] === $tplA['name']);

    // --- issuance emails the earner; a mail failure keeps the badge (RES-002) -----
    $sentFlag = static fn (?string $uuid): ?int => $uuid === null ? null : (int) $db->fetchColumn('SELECT notification_sent FROM issued_badges WHERE uuid = ? AND status = ?', [$uuid, 'pending']);
    $resp = send('POST', '/api/v1/badges/issue', json_encode(['template_id' => $tplA['uuid'], 'email' => 'rest' . MARK, 'first_name' => 'Rest', 'last_name' => 'Earner']), ['Authorization' => 'Bearer ' . $keyA]);
    $d = body($resp)['data'] ?? [];
    check('REST issue with failing SMTP is still 201 with notification_sent=false', $resp->status() === 201 && ($d['notification_sent'] ?? null) === false && $sentFlag($d['badge_id'] ?? null) === 0);
    check('the failed issuance mail was spooled', glob(BASE_PATH . '/storage/mail/*rest' . MARK . '*') !== []);
    $r = $call($keyA, 'issue_badge', ['template_id' => $tplA['uuid'], 'email' => 'mcp' . MARK, 'first_name' => 'Mcp', 'last_name' => 'Earner']);
    check('MCP issue_badge with failing SMTP still issues', !isset($r['isError']) && ($r['structuredContent']['notification_sent'] ?? null) === false && $sentFlag($r['structuredContent']['badge_id'] ?? null) === 0);
    $resp = send('POST', '/api/v1/badges/bulk-issue', json_encode(['template_id' => $tplA['uuid'], 'earners' => [
        ['email' => 'bulk1' . MARK, 'first_name' => 'Bulk', 'last_name' => 'One'],
        ['email' => 'bad', 'first_name' => 'Bad', 'last_name' => 'Email'],
        ['email' => 'bulk2' . MARK, 'first_name' => 'Bulk', 'last_name' => 'Two'],
    ]]), ['Authorization' => 'Bearer ' . $keyA]);
    $res = body($resp)['data']['results'] ?? [];
    check('REST bulk issues the valid earners and keeps going', $resp->status() === 200 && count($res) === 3 && $res[0]['ok'] === true && $res[1]['ok'] === false && $res[2]['ok'] === true);
    // ponytail: like the CSV import, sendMany counts a spooled mail as sent, so only parity with the DB flag is asserted.
    check('REST bulk notification_sent matches the badge flag', is_bool($res[0]['notification_sent'] ?? null) && $sentFlag($res[0]['badge_id'] ?? null) === (int) $res[0]['notification_sent'] && $sentFlag($res[2]['badge_id'] ?? null) === (int) $res[2]['notification_sent']);
    check('REST bulk spooled one mail per issued earner', glob(BASE_PATH . '/storage/mail/*bulk1' . MARK . '*') !== [] && glob(BASE_PATH . '/storage/mail/*bulk2' . MARK . '*') !== []);
    $tools = array_column(body(rpc($keyA, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']))['result']['tools'], null, 'name');
    check('bulk maxItems is ApiOperations::BULK_MAX', ($tools['bulk_issue_badges']['inputSchema']['properties']['earners']['maxItems'] ?? null) === \HexBadge\Services\ApiOperations::BULK_MAX);

    // --- protocol errors ----------------------------------------------------------
    $r = body(rpc($read, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list']));
    check('unknown method is -32601', ($r['error']['code'] ?? null) === -32601 && ($r['id'] ?? null) === 8);
    $resp = send('POST', '/mcp', '{"jsonrpc":', ['Authorization' => 'Bearer ' . $read]);
    check('invalid JSON is -32700', (body($resp)['error']['code'] ?? null) === -32700 && $resp->status() === 400);
    $resp = send('POST', '/mcp', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', ['Authorization' => 'Bearer ' . $read]);
    check('batches are rejected with -32600', (body($resp)['error']['code'] ?? null) === -32600);
    check('missing jsonrpc version is -32600', (body(rpc($read, ['id' => 9, 'method' => 'ping']))['error']['code'] ?? null) === -32600);

    // --- auth / origin / methods --------------------------------------------------
    $resp = send('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    check('missing auth is 401 with WWW-Authenticate', $resp->status() === 401 && header_of($resp, 'WWW-Authenticate') === 'Bearer' && isset(body($resp)['error']));
    $resp = send('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Authorization' => 'Bearer hxb_invalid']);
    check('invalid key is 401', $resp->status() === 401);
    check('foreign Origin is 403', rpc($read, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://evil.example'])->status() === 403);
    check('own Origin is allowed', rpc($read, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => (string) config('app.url')])->status() === 200);
    $resp = send('GET', '/mcp', null, ['Authorization' => 'Bearer ' . $read]);
    check('GET is 405 with Allow: POST', $resp->status() === 405 && header_of($resp, 'Allow') === 'POST');
    check('DELETE is 405', send('DELETE', '/mcp')->status() === 405);
    check('responses are JSON', str_starts_with((string) header_of(rpc($read, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), 'Content-Type'), 'application/json'));

    // --- API v1 regression (same ApiOperations) -----------------------------------
    $resp = send('GET', '/api/v1/templates', null, ['Authorization' => 'Bearer ' . $read]);
    $b = body($resp);
    check('REST list templates still works', $resp->status() === 200 && $b['success'] === true && is_array($b['data']) && ($b['meta']['version'] ?? null) === '1.0');
    check('REST and MCP return the same templates', $b['data'] === ($call($read, 'list_templates')['structuredContent']['items'] ?? null));
    $resp = send('GET', '/api/v1/templates/' . uuid4(), null, ['Authorization' => 'Bearer ' . $read]);
    check('REST unknown template is 404 TEMPLATE_NOT_FOUND', $resp->status() === 404 && (body($resp)['error']['code'] ?? null) === 'TEMPLATE_NOT_FOUND');
    $resp = send('GET', '/api/v1/badges/' . uuid4(), null, ['Authorization' => 'Bearer ' . $read]);
    check('REST missing scope is 403 INSUFFICIENT_SCOPE', $resp->status() === 403 && (body($resp)['error']['code'] ?? null) === 'INSUFFICIENT_SCOPE');
    $resp = send('GET', '/api/v1/templates');
    check('REST without token is 401 INVALID_TOKEN', $resp->status() === 401 && (body($resp)['error']['code'] ?? null) === 'INVALID_TOKEN');
    $resp = send('DELETE', '/api/v1/badges/' . uuid4(), '{"reason":"x"}', ['Authorization' => 'Bearer ' . $issue]);
    check('REST revoke of an unknown badge is 404 NOT_FOUND', $resp->status() === 404 && (body($resp)['error']['code'] ?? null) === 'NOT_FOUND');
    $resp = send('POST', '/api/v1/badges/issue', '{"template_id":"bad"}', ['Authorization' => 'Bearer ' . $issue]);
    check('REST issue validation is 422 VALIDATION_ERROR', $resp->status() === 422 && (body($resp)['error']['message'] ?? null) === 'UUID inválido');
    check('REST calls are audited', (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs a JOIN api_keys k ON k.id = a.api_key_id JOIN users u ON u.id = k.user_id WHERE u.email = ? AND a.action = 'api.request'", ['owner' . MARK]) > 0);
} finally {
    cleanup($db);
}

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
