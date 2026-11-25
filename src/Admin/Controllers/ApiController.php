<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Services\ApiOperations;

/**
 * API REST v1 (CLAUDE.md §6.4).
 *
 * Autenticación: Authorization: Bearer <api_key>. Scopes granulares.
 * Rate limit: 100 req/min por API key. Respuestas JSON estandarizadas.
 * La lógica vive en ApiOperations (compartida con el servidor MCP); acá solo
 * se traduce a HTTP + el sobre {success, data|error, meta}.
 */
final class ApiController
{
    // ---- Endpoints ---------------------------------------------------

    public function listTemplates(Request $request): Response
    {
        return $this->run($request, 'listTemplates');
    }

    public function getTemplate(Request $request, string $uuid): Response
    {
        return $this->run($request, 'getTemplate', $uuid);
    }

    public function issueBadge(Request $request): Response
    {
        return $this->run($request, 'issueBadge', $request->json() ?? []);
    }

    public function bulkIssue(Request $request): Response
    {
        return $this->run($request, 'bulkIssue', $request->json() ?? []);
    }

    public function getBadge(Request $request, string $uuid): Response
    {
        return $this->run($request, 'getBadge', $uuid);
    }

    public function revokeBadge(Request $request, string $uuid): Response
    {
        return $this->run($request, 'revokeBadge', $uuid, $request->json() ?? []);
    }

    public function earnerBadges(Request $request, string $email): Response
    {
        return $this->run($request, 'earnerBadges', rawurldecode($email));
    }

    // ---- Infra -------------------------------------------------------

    /**
     * Autentica (Bearer + rate limit), valida el scope de la operación,
     * audita y ejecuta.
     */
    private function run(Request $request, string $operation, mixed ...$args): Response
    {
        $api = ApiOperations::authenticate($request);
        if (is_array($api)) {
            return $this->respond($api);
        }
        if (!$api->allows($operation)) {
            return $this->error('INSUFFICIENT_SCOPE', 'Scope no autorizado', 403);
        }
        $api->audit($request);
        return $this->respond($api->$operation(...$args));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function respond(array $result): Response
    {
        if (isset($result['error'])) {
            return $this->error($result['error']['code'], $result['error']['message'], $result['status']);
        }
        return $this->ok($result['data'], $result['status']);
    }

    private function ok(mixed $data, int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data'    => $data,
            'meta'    => ['timestamp' => date('c'), 'version' => '1.0'],
        ], $status);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message],
            'meta'    => ['timestamp' => date('c')],
        ], $status);
    }
}
