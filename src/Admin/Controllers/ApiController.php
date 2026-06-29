<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Logger;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;
use HexBadge\Models\User;
use HexBadge\Services\ApiKeyService;
use HexBadge\Services\BadgeService;

/**
 * API REST v1 (CLAUDE.md §6.4).
 *
 * Autenticación: Authorization: Bearer <api_key>. Scopes granulares.
 * Rate limit: 100 req/min por API key. Respuestas JSON estandarizadas.
 */
final class ApiController
{
    /** @var array<string,mixed>|null Registro de la API key autenticada. */
    private ?array $apiKey = null;

    /** Empresa del dueño de la API key. NULL = superadmin global (sin filtro). */
    private ?int $apiCompanyId = null;

    /** ¿La API key puede operar sobre una entidad de esa empresa? */
    private function apiCompanyAllows(?int $entityCompanyId): bool
    {
        return $this->apiCompanyId === null || $this->apiCompanyId === $entityCompanyId;
    }

    // ---- Endpoints ---------------------------------------------------

    public function listTemplates(Request $request): Response
    {
        if ($r = $this->guard($request, 'templates:read', 'badges:read')) {
            return $r;
        }
        $templates = array_map([$this, 'presentTemplate'], BadgeTemplate::active($this->apiCompanyId));
        return $this->ok($templates);
    }

    public function getTemplate(Request $request, string $uuid): Response
    {
        if ($r = $this->guard($request, 'templates:read', 'badges:read')) {
            return $r;
        }
        $t = BadgeTemplate::findByUuid($uuid);
        if ($t === null || !$this->apiCompanyAllows(isset($t['company_id']) ? (int) $t['company_id'] : null)) {
            return $this->error('TEMPLATE_NOT_FOUND', 'Template no encontrado', 404);
        }
        return $this->ok($this->presentTemplate($t));
    }

    public function issueBadge(Request $request): Response
    {
        if ($r = $this->guard($request, 'badges:issue')) {
            return $r;
        }
        $body = $request->json() ?? [];
        $v    = new Validator();
        try {
            $templateUuid = $v->uuid((string) ($body['template_id'] ?? ''));
            $email        = $v->email((string) ($body['email'] ?? ''));
            $firstName    = $v->name((string) ($body['first_name'] ?? ''));
            $lastName     = $v->name((string) ($body['last_name'] ?? ''));
            $locale       = $v->locale((string) ($body['locale'] ?? 'es'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $tpl = BadgeTemplate::findByUuid($templateUuid);
        if ($tpl === null || !$this->apiCompanyAllows(isset($tpl['company_id']) ? (int) $tpl['company_id'] : null)) {
            return $this->error('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404);
        }

        $result = (new BadgeService())->issue($templateUuid, $email, $firstName, $lastName, (int) $this->apiKey['user_id'], 'api', $locale);
        if (!$result['ok']) {
            return match ($result['reason']) {
                'duplicate'          => $this->error('DUPLICATE_BADGE', 'El earner ya tiene este badge activo', 409),
                'template_not_found' => $this->error('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404),
                default              => $this->error('VALIDATION_ERROR', 'No se pudo emitir', 422),
            };
        }
        return $this->ok(['badge_id' => $result['badge_uuid'], 'status' => 'pending'], 201);
    }

    public function bulkIssue(Request $request): Response
    {
        if ($r = $this->guard($request, 'bulk:issue')) {
            return $r;
        }
        $body = $request->json() ?? [];
        $templateUuid = (string) ($body['template_id'] ?? '');
        $earners      = $body['earners'] ?? [];
        if (!is_array($earners) || $earners === []) {
            return $this->error('VALIDATION_ERROR', 'earners requerido (array)', 422);
        }
        if (count($earners) > 100) {
            return $this->error('VALIDATION_ERROR', 'Máximo 100 earners por request', 422);
        }

        $tpl = BadgeTemplate::findByUuid($templateUuid);
        if ($tpl === null || !$this->apiCompanyAllows(isset($tpl['company_id']) ? (int) $tpl['company_id'] : null)) {
            return $this->error('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404);
        }

        $service = new BadgeService();
        $v       = new Validator();
        $results = [];
        foreach ($earners as $i => $earner) {
            try {
                $email     = $v->email((string) ($earner['email'] ?? ''));
                $firstName = $v->name((string) ($earner['first_name'] ?? ''));
                $lastName  = $v->name((string) ($earner['last_name'] ?? ''));
                $r = $service->issue($templateUuid, $email, $firstName, $lastName, (int) $this->apiKey['user_id'], 'api');
                $results[] = ['email' => $email, 'ok' => $r['ok'], 'badge_id' => $r['badge_uuid'], 'reason' => $r['reason']];
            } catch (\InvalidArgumentException $e) {
                $results[] = ['index' => $i, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }
        return $this->ok(['results' => $results]);
    }

    public function getBadge(Request $request, string $uuid): Response
    {
        if ($r = $this->guard($request, 'badges:read')) {
            return $r;
        }
        $b = IssuedBadge::findFullByUuid($uuid);
        if ($b === null || !$this->apiCompanyAllows(isset($b['company_id']) ? (int) $b['company_id'] : null)) {
            return $this->error('NOT_FOUND', 'Badge no encontrado', 404);
        }
        return $this->ok($this->presentBadge($b));
    }

    public function revokeBadge(Request $request, string $uuid): Response
    {
        if ($r = $this->guard($request, 'badges:issue')) {
            return $r;
        }
        $body   = $request->json() ?? [];
        $reason = (string) ($body['reason'] ?? 'Revocado vía API');

        $b = IssuedBadge::findFullByUuid($uuid);
        if ($b === null || !$this->apiCompanyAllows(isset($b['company_id']) ? (int) $b['company_id'] : null)) {
            return $this->error('NOT_FOUND', 'Badge no encontrado', 404);
        }

        $ok = (new BadgeService())->revoke($uuid, $reason, (int) $this->apiKey['user_id']);
        if (!$ok) {
            return $this->error('NOT_FOUND', 'Badge no encontrado o ya revocado', 404);
        }
        return $this->ok(['badge_id' => $uuid, 'status' => 'revoked']);
    }

    public function earnerBadges(Request $request, string $email): Response
    {
        if ($r = $this->guard($request, 'badges:read')) {
            return $r;
        }
        try {
            $email = (new Validator())->email(rawurldecode($email));
        } catch (\InvalidArgumentException) {
            return $this->error('VALIDATION_ERROR', 'Email inválido', 422);
        }
        $earner = Earner::findByEmail($email);
        if ($earner === null) {
            return $this->error('NOT_FOUND', 'Earner no encontrado', 404);
        }
        $badges = IssuedBadge::acceptedForEarner((int) $earner['id']);
        // Aislar a la empresa de la API key (la persona puede tener badges de varias).
        if ($this->apiCompanyId !== null) {
            $badges = array_values(array_filter(
                $badges,
                fn (array $b): bool => (int) ($b['company_id'] ?? 0) === $this->apiCompanyId
            ));
        }
        return $this->ok([
            'earner' => ['email' => $earner['email'], 'name' => $earner['display_name'], 'uuid' => $earner['uuid']],
            'badges' => array_map(static fn (array $b): array => [
                'badge_id'  => $b['uuid'],
                'name'      => $b['template_name'],
                'status'    => $b['status'],
                'issued_at' => $b['issued_at'],
            ], $badges),
        ]);
    }

    // ---- Infra -------------------------------------------------------

    /**
     * Autentica por Bearer, valida scope y aplica rate limit.
     * Devuelve una Response de error o null si todo OK.
     */
    private function guard(Request $request, string ...$acceptedScopes): ?Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            return $this->error('INVALID_TOKEN', 'Falta el token Bearer', 401);
        }

        $key = (new ApiKeyService())->verify($raw);
        if ($key === null) {
            return $this->error('INVALID_TOKEN', 'API key inválida o expirada', 401);
        }
        $this->apiKey = $key;

        // Empresa del dueño de la key (multitenancy): scopea todos los endpoints.
        $owner = User::find((int) $key['user_id']);
        $this->apiCompanyId = ($owner !== null && $owner['company_id'] !== null) ? (int) $owner['company_id'] : null;

        // Rate limit por API key.
        $limiter = new RateLimiter();
        if (!$limiter->check('apikey:' . $key['id'], 'api', (int) config('rate_limit.api', 100), 60)) {
            return $this->error('RATE_LIMITED', 'Demasiadas solicitudes', 429);
        }

        // Scope: basta con tener uno de los aceptados.
        $hasScope = false;
        foreach ($acceptedScopes as $scope) {
            if (ApiKeyService::hasScope($key, $scope)) {
                $hasScope = true;
                break;
            }
        }
        if (!$hasScope) {
            return $this->error('INSUFFICIENT_SCOPE', 'Scope no autorizado', 403);
        }

        Logger::audit('api.request', null, null, null, ['path' => $request->uri()], (int) $key['id'], $request->ip(), $request->userAgent());
        return null;
    }

    /**
     * @param mixed $data
     */
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

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function presentTemplate(array $t): array
    {
        return [
            'id'          => $t['uuid'],
            'name'        => $t['name'],
            'description' => $t['description'],
            'image'       => badge_image_url((string) $t['image_filename']),
            'tags'        => BadgeTemplate::decodeTags($t['skills_tags'] ?? null),
            'state'       => $t['state'],
        ];
    }

    /**
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function presentBadge(array $b): array
    {
        return [
            'badge_id'  => $b['uuid'],
            'template'  => $b['template_name'] ?? null,
            'recipient' => $b['earner_email'] ?? null,
            'status'    => $b['status'],
            'issued_at' => $b['issued_at'],
            'expires_at'=> $b['expires_at'] ?? null,
            'verify_url'=> public_url('verify/' . $b['uuid']),
        ];
    }
}
