<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Services\ApiOperations;

/**
 * Remote MCP server (Streamable HTTP, stateless, JSON-only): POST /mcp.
 *
 * Thin layer over ApiOperations, the same logic behind API v1: same API keys,
 * scopes, rate limit and company scoping. No SSE and no sessions.
 * Docs: docs/mcp.md.
 */
final class McpController
{
    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26']; // newest first
    private const MAX_BODY_BYTES    = 1_048_576;

    private const UUID   = ['type' => 'string', 'format' => 'uuid'];
    private const EMAIL  = ['type' => 'string', 'format' => 'email'];
    private const PERSON = [
        'email'      => self::EMAIL + ['description' => 'Earner email'],
        'first_name' => ['type' => 'string', 'maxLength' => 100],
        'last_name'  => ['type' => 'string', 'maxLength' => 100],
    ];

    /**
     * Tool name => ApiOperations operation, description, input schema and annotations.
     * Parameter names match the REST v1 bodies (template_id, earners, reason, ...).
     *
     * @return array<string,array{operation:string,description:string,schema:array<string,mixed>,annotations:array<string,bool>}>
     */
    private static function tools(): array
    {
        $read = ['readOnlyHint' => true];
        return [
            'list_templates' => [
                'operation'   => 'listTemplates',
                'description' => 'List the active badge templates available to this API key.',
                'schema'      => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => $read,
            ],
            'get_template' => [
                'operation'   => 'getTemplate',
                'description' => 'Get one badge template by its UUID.',
                'schema'      => ['type' => 'object', 'properties' => ['uuid' => self::UUID], 'required' => ['uuid']],
                'annotations' => $read,
            ],
            'issue_badge' => [
                'operation'   => 'issueBadge',
                'description' => 'Issue a badge from a template to one earner. The earner is emailed a link to accept it; the badge is issued even if the email fails (see notification_sent).',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => ['template_id' => self::UUID + ['description' => 'Template UUID']] + self::PERSON
                        + ['locale' => ['type' => 'string', 'enum' => ['es', 'en'], 'default' => 'es']],
                    'required'   => ['template_id', 'email', 'first_name', 'last_name'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ],
            'bulk_issue_badges' => [
                'operation'   => 'bulkIssue',
                'description' => 'Issue a badge from a template to up to ' . ApiOperations::BULK_MAX . ' earners at once. Each issued earner is emailed a link to accept it. Returns one result per earner.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'template_id' => self::UUID + ['description' => 'Template UUID'],
                        'earners'     => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => ApiOperations::BULK_MAX,
                            'items'    => ['type' => 'object', 'properties' => self::PERSON, 'required' => ['email', 'first_name', 'last_name']],
                        ],
                    ],
                    'required'   => ['template_id', 'earners'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ],
            'get_badge' => [
                'operation'   => 'getBadge',
                'description' => 'Get one issued badge by its UUID.',
                'schema'      => ['type' => 'object', 'properties' => ['uuid' => self::UUID], 'required' => ['uuid']],
                'annotations' => $read,
            ],
            'revoke_badge' => [
                'operation'   => 'revokeBadge',
                'description' => 'Revoke an issued badge. It stops verifying as valid. Cannot be undone.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => ['uuid' => self::UUID, 'reason' => ['type' => 'string', 'description' => 'Defaults to "Revocado vía API"']],
                    'required'   => ['uuid'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
            ],
            'list_earner_badges' => [
                'operation'   => 'earnerBadges',
                'description' => "List an earner's accepted badges by email.",
                'schema'      => ['type' => 'object', 'properties' => ['email' => self::EMAIL], 'required' => ['email']],
                'annotations' => $read,
            ],
        ];
    }

    public function handle(Request $request): Response
    {
        $origin = $request->header('Origin');
        if ($origin !== null && !in_array(strtolower(rtrim($origin, '/')), (array) config('mcp.allowed_origins', []), true)) {
            return $this->error(null, -32000, 'Origin not allowed', 403);
        }

        $api = ApiOperations::authenticate($request);
        if (is_array($api)) {
            $headers = $api['status'] === 401 ? ['WWW-Authenticate' => 'Bearer'] : [];
            return $this->error(null, -32000, $api['error']['message'], $api['status'], $headers);
        }

        $raw = $request->body(self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->error(null, -32600, 'Request body too large', 413);
        }
        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error(null, -32700, 'Parse error', 400);
        }
        if (!$decoded instanceof \stdClass) {
            // Batches (arrays) are not supported: stateless, one message per request.
            return $this->error(null, -32600, 'Invalid Request', 400);
        }
        /** @var array<string,mixed> $msg */
        $msg = json_decode($raw, true);

        // Responses or notifications from the client: accepted, nothing to answer.
        if (!array_key_exists('id', $msg)) {
            return ($msg['jsonrpc'] ?? null) === '2.0' && is_string($msg['method'] ?? null)
                ? new Response('', 202)
                : $this->error(null, -32600, 'Invalid Request', 400);
        }
        $id = $msg['id'];
        if (!is_string($id) && !is_int($id)) {
            return $this->error(null, -32600, 'Invalid Request', 400);
        }
        if (!isset($msg['method']) && (array_key_exists('result', $msg) || array_key_exists('error', $msg))) {
            return new Response('', 202);
        }
        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null)) {
            return $this->error($id, -32600, 'Invalid Request', 400);
        }
        $params = $msg['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, -32602, 'Invalid params');
        }

        return match ($msg['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, self::PROTOCOL_VERSIONS, true)
                    ? $params['protocolVersion']
                    : self::PROTOCOL_VERSIONS[0],
                'capabilities'    => ['tools' => new \stdClass()],
                'serverInfo'      => ['name' => 'hexbadge', 'version' => hexbadge_version()],
            ]),
            'ping'       => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->listTools($api)]),
            'tools/call' => $this->callTool($request, $api, $id, $params),
            default      => $this->error($id, -32601, 'Method not found'),
        };
    }

    /** GET (SSE stream) and DELETE (session end) are not offered: stateless server. */
    public function methodNotAllowed(Request $request): Response
    {
        return $this->error(null, -32000, 'Method not allowed: use POST', 405, ['Allow' => 'POST']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listTools(ApiOperations $api): array
    {
        $out = [];
        foreach (self::tools() as $name => $tool) {
            if ($api->allows($tool['operation'])) {
                $out[] = ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['schema'], 'annotations' => $tool['annotations']];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function callTool(Request $request, ApiOperations $api, string|int $id, array $params): Response
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? (self::tools()[$name] ?? null) : null;
        $args = $params['arguments'] ?? [];
        if ($tool === null || !is_array($args) || ($args !== [] && array_is_list($args))) {
            return $this->error($id, -32602, 'Unknown tool or invalid arguments');
        }

        if (!$api->allows($tool['operation'])) {
            return $this->toolError($id, 'Scope no autorizado');
        }
        if (($problem = $this->checkArguments($tool['schema'], $args)) !== null) {
            return $this->toolError($id, $problem);
        }
        $api->audit($request, ['tool' => $name]);

        try {
            $result = match ($tool['operation']) {
                'listTemplates' => $api->listTemplates(),
                'getTemplate'   => $api->getTemplate($args['uuid']),
                'issueBadge'    => $api->issueBadge($args),
                'bulkIssue'     => $api->bulkIssue($args),
                'getBadge'      => $api->getBadge($args['uuid']),
                'revokeBadge'   => $api->revokeBadge($args['uuid'], $args),
                'earnerBadges'  => $api->earnerBadges($args['email']),
            };
        } catch (\Throwable $e) {
            Logger::app('error', sprintf('MCP %s: %s en %s:%d — %s', $name, $e::class, $e->getFile(), $e->getLine(), $e->getMessage()));
            return $this->toolError($id, 'Error interno al ejecutar la herramienta');
        }

        if (isset($result['error'])) {
            return $this->toolError($id, $result['error']['message']);
        }
        $data = $result['data'];
        // ponytail: structuredContent must be an object, so lists are wrapped.
        if (is_array($data) && array_is_list($data)) {
            $data = ['items' => $data];
        }
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->result($id, ['content' => [['type' => 'text', 'text' => $text === false ? '{}' : $text]], 'structuredContent' => $data]);
    }

    /**
     * Top-level type check against the tool's inputSchema; the operation itself
     * does the real validation (same as REST). Keeps non-strings out of (string) casts.
     *
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $args
     */
    private function checkArguments(array $schema, array $args): ?string
    {
        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $args)) {
                return "Falta el parámetro {$field}";
            }
        }
        foreach ((array) $schema['properties'] as $field => $spec) {
            if (!array_key_exists($field, $args)) {
                continue;
            }
            $ok = $spec['type'] === 'array' ? is_array($args[$field]) && array_is_list($args[$field]) : is_string($args[$field]);
            if (!$ok) {
                return "Tipo inválido para {$field}: se esperaba {$spec['type']}";
            }
        }
        return null;
    }

    /** Business or validation failure: a tool result the model can read, not a protocol error. */
    private function toolError(string|int $id, string $message): Response
    {
        return $this->result($id, ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]]);
    }

    private function result(string|int $id, mixed $result): Response
    {
        return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param array<string,string> $headers
     */
    private function error(string|int|null $id, int $code, string $message, int $status = 200, array $headers = []): Response
    {
        return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
