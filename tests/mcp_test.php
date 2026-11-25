<?php

declare(strict_types=1);

/**
 * MCP server (POST /mcp) against the real database, plus an API v1 regression pass over the
 * shared ApiOperations: JSON-RPC handling, Bearer auth, scopes, Origin check, HTTP methods.
 * Drives the real router and controllers; the request body is injected through Request.
 *
 * Needs MySQL with the schema: docker exec -e HEXBADGE_ALLOW_DB_TEST=1 hexbadge_admin php tests/mcp_test.php
 * Only touches marked test data: users and earners @hbmcp.invalid, companies and templates named
 * "HB MCP Test ...", their badges and image files, API keys, audit and rate-limit rows and
 * spooled mails; all deleted at the end. The test company's SMTP points at a closed local port, so no real email
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
    foreach ($db->fetchAll("SELECT image_filename FROM badge_templates WHERE name LIKE '" . TNAME . "%'") as $row) {
        (new \HexBadge\Services\ImageService())->delete((string) $row['image_filename']);
    }
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
    $writeA = $keys->generate($userA, 'hbmcp write A', ['templates:write'])['key'];
    $globalWrite = $keys->generate($userId, 'hbmcp global write', ['templates:write'])['key'];

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

    // --- template writes (templates:write) ---------------------------------------
    check('tools/list for templates:write', $names($writeA) === ['create_template', 'archive_template']);
    $tools = array_column(body(rpc($writeA, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']))['result']['tools'], null, 'name');
    check('archive_template is annotated destructive', ($tools['archive_template']['annotations']['destructiveHint'] ?? null) === true);
    check('create_template has no state parameter', !isset($tools['create_template']['inputSchema']['properties']['state']));

    $png = static function (int $w = 8, int $h = 8): string {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    };
    $tplArgs = static fn (array $image, string $suffix = 'created'): array => [
        'name' => TNAME . ' ' . $suffix, 'description' => 'Created over MCP', 'criteria_text' => 'Pass the test',
        'expires_days' => 30, 'is_public' => false, 'skills_tags' => ['mcp', 'testing'], 'image' => $image,
    ];
    $tmpBefore = glob(sys_get_temp_dir() . '/hbimg*') ?: [];

    $r = $call($writeA, 'create_template', $tplArgs(['data' => base64_encode($png()), 'mime_type' => 'image/png']));
    $created = $r['structuredContent'] ?? [];
    $row = isset($created['id']) ? $db->fetchOne('SELECT * FROM badge_templates WHERE uuid = ?', [$created['id']]) : null;
    check('create_template succeeds as draft', !isset($r['isError']) && ($created['state'] ?? null) === 'draft' && ($row['state'] ?? null) === 'draft');
    check('created template belongs to the key company with the panel field semantics', $row !== null && (int) $row['company_id'] === $coA && (int) $row['is_public'] === 0
        && (int) $row['expires_days'] === 30 && ($created['tags'] ?? null) === ['mcp', 'testing'] && $row['certificate_template_id'] === null && $row['certificate_filename'] === null);
    check('created image is stored as WebP on disk', $row !== null && str_ends_with((string) $row['image_filename'], '.webp') && is_file(BASE_PATH . '/apps/earner/public/uploads/badges/' . $row['image_filename']));
    check('template creation is audited', $row !== null && (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'template.created' AND entity_id = ?", [$row['uuid']]) === 1);

    $files = static fn (): int => count(glob(BASE_PATH . '/apps/earner/public/uploads/badges/*') ?: []);
    $before = $files();
    $err = static fn (array $r): string => ($r['isError'] ?? false) === true ? (string) ($r['content'][0]['text'] ?? '') : '';
    check('invalid base64 is a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => 'not*base64!', 'mime_type' => 'image/png']))) === 'image.data no es base64 válido');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>';
    check('SVG mime_type is a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($svg), 'mime_type' => 'image/svg+xml']))) !== '');
    check('SVG bytes declared as PNG are a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($svg), 'mime_type' => 'image/png']))) !== '');
    check('text bytes declared as PNG are a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode('hello, not an image'), 'mime_type' => 'image/png']))) !== '');
    check('PNG bytes declared as JPEG are a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($png()), 'mime_type' => 'image/jpeg']))) !== '');
    check('oversize image is a tool error', $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode(str_repeat("\0", 5 * 1024 * 1024 + 1)), 'mime_type' => 'image/png']))) === 'Imagen demasiado grande (máx. 5MB)');
    $edge = \HexBadge\Services\ApiOperations::IMAGE_MAX_EDGE;
    check('IMAGE_MAX_EDGE is 2048', $edge === 2048);
    check('image wider than IMAGE_MAX_EDGE is a tool error', str_contains($err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($png($edge + 1, 1)), 'mime_type' => 'image/png']))), $edge . 'px'));
    check('tool description states IMAGE_MAX_EDGE', str_contains($tools['create_template']['description'] ?? '', $edge . 'px'));
    // Animated WebP built by hand: a GD lossless frame wrapped in VP8X/ANIM/ANMF. GD cannot decode it.
    $animatedWebp = (static function (): string {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagewebp($img, null, IMG_WEBP_LOSSLESS);
        $frame = substr((string) ob_get_clean(), 12); // the VP8L chunk
        $chunk = static fn (string $tag, string $data): string => $tag . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
        $u24   = static fn (int $n): string => substr(pack('V', $n), 0, 3);
        $anmf  = $chunk('ANMF', $u24(0) . $u24(0) . $u24(7) . $u24(7) . $u24(100) . "\0" . $frame);
        $body  = 'WEBP' . $chunk('VP8X', "\x12\0\0\0" . $u24(7) . $u24(7)) . $chunk('ANIM', "\0\0\0\0\0\0") . $anmf . $anmf;
        return 'RIFF' . pack('V', strlen($body)) . $body;
    })();
    $animErr = $err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($animatedWebp), 'mime_type' => 'image/webp'])));
    check('animated WebP is a tool error', str_contains($animErr, 'No se pudo procesar la imagen'));
    foreach (['empty' => [''], 'whitespace-only' => ['  '], 'comma' => ['a,b']] as $case => $bad) {
        check("skills_tags with a {$case} tag is a tool error", str_contains($err($call($writeA, 'create_template', ['skills_tags' => $bad] + $tplArgs(['data' => base64_encode($png()), 'mime_type' => 'image/png']))), 'no puede contener comas'));
    }
    check('missing image is a tool error', $err($call($writeA, 'create_template', array_diff_key($tplArgs([]), ['image' => 1]))) === 'Falta el parámetro image');
    check('wrongly typed is_public is a tool error', str_contains($err($call($writeA, 'create_template', ['is_public' => 'yes'] + $tplArgs(['data' => base64_encode($png()), 'mime_type' => 'image/png']))), 'is_public'));
    check('create_template without templates:write is scope-denied', $err($call($keyA, 'create_template', $tplArgs(['data' => base64_encode($png()), 'mime_type' => 'image/png']))) === 'Scope no autorizado');
    check('rejected creations leave no template and no image', $files() === $before
        && (int) $db->fetchColumn("SELECT COUNT(*) FROM badge_templates WHERE name LIKE '" . TNAME . "%'") === 3);
    check('no image temp files are left behind', (glob(sys_get_temp_dir() . '/hbimg*') ?: []) === $tmpBefore);

    // --- GD without WebP (like prod) ---------------------------------------------
    $stored = static fn (array $r): string => isset($r['structuredContent']['id'])
        ? (string) $db->fetchColumn('SELECT image_filename FROM badge_templates WHERE uuid = ?', [$r['structuredContent']['id']]) : '';
    $badgeDir = BASE_PATH . '/apps/earner/public/uploads/badges/';
    $staticWebp = (static function (): string {
        ob_start();
        imagewebp(imagecreatetruecolor(8, 8), null, 80);
        return (string) ob_get_clean();
    })();
    \HexBadge\Services\ImageService::$forceNoWebp = true;
    try {
        $name = $stored($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($png(1254, 800)), 'mime_type' => 'image/png'], 'nowebp png')));
        $info = $name !== '' ? @getimagesize($badgeDir . $name) : false;
        check('without WebP a PNG is stored as .png', str_ends_with($name, '.png') && is_file($badgeDir . $name));
        check('without WebP the PNG is valid and reduced to 512px', is_array($info) && $info[2] === IMAGETYPE_PNG && max($info[0], $info[1]) === 512);
        $jpg = (static function (): string {
            ob_start();
            imagejpeg(imagecreatetruecolor(1000, 600), null, 80);
            return (string) ob_get_clean();
        })();
        $name = $stored($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($jpg), 'mime_type' => 'image/jpeg'], 'nowebp jpg')));
        $info = $name !== '' ? @getimagesize($badgeDir . $name) : false;
        check('without WebP a JPEG is stored as a reduced .jpg', str_ends_with($name, '.jpg') && is_array($info) && $info[2] === IMAGETYPE_JPEG && max($info[0], $info[1]) === 512);
        $before = $files();
        check('without WebP a WebP upload is a tool error', str_contains($err($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($staticWebp), 'mime_type' => 'image/webp'], 'nowebp webp'))), 'No se pudo procesar la imagen'));
        check('the rejected WebP leaves no file', $files() === $before);
    } finally {
        \HexBadge\Services\ImageService::$forceNoWebp = false;
    }
    $name = $stored($call($writeA, 'create_template', $tplArgs(['data' => base64_encode($staticWebp), 'mime_type' => 'image/webp'], 'webp')));
    check('with WebP a static WebP is stored as .webp', str_ends_with($name, '.webp') && is_file($badgeDir . $name));

    // --- create_template company_id (global keys pick the company) ---------------
    $uuidA   = (string) $db->fetchColumn('SELECT uuid FROM companies WHERE id = ?', [$coA]);
    $uuidB   = (string) $db->fetchColumn('SELECT uuid FROM companies WHERE id = ?', [$coB]);
    $img     = ['data' => base64_encode($png()), 'mime_type' => 'image/png'];
    $coOf    = static fn (array $r): ?int => isset($r['structuredContent']['id']) ? (int) $db->fetchColumn('SELECT company_id FROM badge_templates WHERE uuid = ?', [$r['structuredContent']['id']]) : null;
    $r = $call($globalWrite, 'create_template', ['company_id' => strtoupper($uuidB)] + $tplArgs($img, 'global B'));
    check('global key with company_id creates in that company', !isset($r['isError']) && $coOf($r) === $coB
        && ($r['structuredContent']['company'] ?? null) === ['id' => $uuidB, 'name' => TNAME . ' B']);
    check('template.created audit carries the resolved company_id', isset($r['structuredContent']['id'])
        && (int) json_decode((string) $db->fetchColumn("SELECT metadata FROM audit_logs WHERE action = 'template.created' AND entity_id = ?", [$r['structuredContent']['id']]), true)['company_id'] === $coB);
    check('global key without company_id is a tool error', str_contains($err($call($globalWrite, 'create_template', $tplArgs($img, 'global none'))), 'indica company_id'));
    check('global key with unknown company_id is not found', $err($call($globalWrite, 'create_template', ['company_id' => uuid4()] + $tplArgs($img, 'global unknown'))) === 'Empresa no encontrada');
    check('company key naming another company is not found', $err($call($writeA, 'create_template', ['company_id' => $uuidB] + $tplArgs($img, 'A as B'))) === 'Empresa no encontrada');
    $r = $call($writeA, 'create_template', ['company_id' => $uuidA] + $tplArgs($img, 'A explicit'));
    check('company key naming its own company creates there', !isset($r['isError']) && $coOf($r) === $coA);
    check('created template defaulted to the key company exposes it', ($created['company'] ?? null) === ['id' => $uuidA, 'name' => TNAME . ' A']);
    check('get_template shows the owning company', ($call($keyA, 'get_template', ['uuid' => $tplA['uuid']])['structuredContent']['company'] ?? null) === ['id' => $uuidA, 'name' => TNAME . ' A']);
    $listed = body(send('GET', '/api/v1/templates', null, ['Authorization' => 'Bearer ' . $keyA]))['data'] ?? [];
    check('REST list_templates shows the owning company', $listed !== [] && array_filter($listed, static fn (array $t): bool => ($t['company']['id'] ?? null) !== $uuidA) === []);
    check('create_template schema has company_id as uuid', ($tools['create_template']['inputSchema']['properties']['company_id']['format'] ?? null) === 'uuid');
    $resp = send('POST', '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'pad' => str_repeat('a', 8 * 1024 * 1024)]), ['Authorization' => 'Bearer ' . $writeA]);
    check('body over 8 MiB is 413', $resp->status() === 413);

    $state = static fn (string $uuid): ?string => $db->fetchColumn('SELECT state FROM badge_templates WHERE uuid = ?', [$uuid]) ?: null;
    $r = $call($writeA, 'archive_template', ['uuid' => $tplA['uuid']]);
    check('archive own template', !isset($r['isError']) && ($r['structuredContent']['state'] ?? null) === 'archived' && $state($tplA['uuid']) === 'archived'
        && (int) $db->fetchColumn('SELECT is_active FROM badge_templates WHERE uuid = ?', [$tplA['uuid']]) === 0);
    check('template archive is audited', (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'template.archived' AND entity_id = ?", [$tplA['uuid']]) === 1);
    $r = $call($writeA, 'archive_template', ['uuid' => $tplA['uuid']]);
    check('archiving an archived template returns it without error', !isset($r['isError']) && ($r['structuredContent']['state'] ?? null) === 'archived');
    $r = $call($writeA, 'archive_template', ['uuid' => $tplB['uuid']]);
    check('company key archiving another company template is not found', $err($r) === 'Template no encontrado' && $state($tplB['uuid']) === 'active');
    check('archive unknown template is not found', $err($call($writeA, 'archive_template', ['uuid' => uuid4()])) === 'Template no encontrado');
    check('archive_template without templates:write is scope-denied', $err($call($keyA, 'archive_template', ['uuid' => $tplB['uuid']])) === 'Scope no autorizado' && $state($tplB['uuid']) === 'active');

    // --- a deactivated owner invalidates its keys ---------------------------------
    $invalid = body(send('GET', '/api/v1/templates', null, ['Authorization' => 'Bearer hxb_invalid']));
    $db->query('UPDATE users SET is_active = 0 WHERE id = ?', [$userA]);
    try {
        $resp = send('GET', '/api/v1/badges/' . uuid4(), null, ['Authorization' => 'Bearer ' . $keyA]);
        check('REST key of a deactivated owner is 401 like an invalid key', $resp->status() === 401 && body($resp)['error'] === $invalid['error']);
        $resp = rpc($keyA, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        check('MCP key of a deactivated owner is 401', $resp->status() === 401);
    } finally {
        $db->query('UPDATE users SET is_active = 1 WHERE id = ?', [$userA]);
    }
    check('the key works again once the owner is reactivated', rpc($keyA, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->status() === 200);

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
