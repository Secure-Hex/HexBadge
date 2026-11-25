<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Logger;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;
use HexBadge\Models\User;

/**
 * Lógica compartida por la API REST v1 y el servidor MCP: autenticación por
 * API key, rate limit, scopes, aislamiento por empresa, operaciones y presenters.
 *
 * Cada operación devuelve un resultado neutro de transporte:
 *   ['status' => int, 'data' => mixed]  o
 *   ['status' => int, 'error' => ['code' => string, 'message' => string]].
 */
final class ApiOperations
{
    /** Scopes aceptados por operación: basta con tener uno. */
    public const SCOPES = [
        'listTemplates' => ['templates:read', 'badges:read'],
        'getTemplate'   => ['templates:read', 'badges:read'],
        'issueBadge'    => ['badges:issue'],
        'bulkIssue'     => ['bulk:issue'],
        'getBadge'      => ['badges:read'],
        'revokeBadge'   => ['badges:issue'],
        'earnerBadges'  => ['badges:read'],
    ];

    /** Máximo de earners por emisión masiva (REST y MCP). */
    public const BULK_MAX = 100;

    /**
     * @param array<string,mixed> $apiKey     Registro de la API key autenticada.
     * @param int|null            $companyId  Empresa del dueño. NULL = superadmin global (sin filtro).
     */
    private function __construct(private array $apiKey, private ?int $companyId)
    {
    }

    /**
     * Autentica por Bearer y aplica el rate limit por API key.
     *
     * @return self|array{status:int,error:array{code:string,message:string}}
     */
    public static function authenticate(Request $request): self|array
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            return self::fail('INVALID_TOKEN', 'Falta el token Bearer', 401);
        }

        $key = (new ApiKeyService())->verify($raw);
        if ($key === null) {
            return self::fail('INVALID_TOKEN', 'API key inválida o expirada', 401);
        }

        // Empresa del dueño de la key (multitenancy): scopea todas las operaciones.
        $owner     = User::find((int) $key['user_id']);
        $companyId = ($owner !== null && $owner['company_id'] !== null) ? (int) $owner['company_id'] : null;

        // Rate limit por API key.
        $limiter = new RateLimiter();
        if (!$limiter->check('apikey:' . $key['id'], 'api', (int) config('rate_limit.api', 100), 60)) {
            return self::fail('RATE_LIMITED', 'Demasiadas solicitudes', 429);
        }

        return new self($key, $companyId);
    }

    /** ¿La key tiene alguno de los scopes que acepta la operación? */
    public function allows(string $operation): bool
    {
        foreach (self::SCOPES[$operation] ?? [] as $scope) {
            if (ApiKeyService::hasScope($this->apiKey, $scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function audit(Request $request, array $metadata = []): void
    {
        Logger::audit('api.request', null, null, null, ['path' => $request->uri()] + $metadata, (int) $this->apiKey['id'], $request->ip(), $request->userAgent());
    }

    // ---- Operaciones -------------------------------------------------

    /** @return array<string,mixed> */
    public function listTemplates(): array
    {
        return self::ok(array_map([$this, 'presentTemplate'], BadgeTemplate::active($this->companyId)));
    }

    /** @return array<string,mixed> */
    public function getTemplate(string $uuid): array
    {
        $t = BadgeTemplate::findByUuid($uuid);
        if ($t === null || !$this->companyAllows($t)) {
            return self::fail('TEMPLATE_NOT_FOUND', 'Template no encontrado', 404);
        }
        return self::ok($this->presentTemplate($t));
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function issueBadge(array $body): array
    {
        $v = new Validator();
        try {
            $templateUuid = $v->uuid((string) ($body['template_id'] ?? ''));
            $email        = $v->email((string) ($body['email'] ?? ''));
            $firstName    = $v->name((string) ($body['first_name'] ?? ''));
            $lastName     = $v->name((string) ($body['last_name'] ?? ''));
            $locale       = $v->locale((string) ($body['locale'] ?? 'es'));
        } catch (\InvalidArgumentException $e) {
            return self::fail('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $tpl = BadgeTemplate::findByUuid($templateUuid);
        if ($tpl === null || !$this->companyAllows($tpl)) {
            return self::fail('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404);
        }

        $service = new BadgeService();
        $result  = $service->issue($templateUuid, $email, $firstName, $lastName, (int) $this->apiKey['user_id'], 'api', $locale);
        if (!$result['ok']) {
            return match ($result['reason']) {
                'duplicate'          => self::fail('DUPLICATE_BADGE', 'El earner ya tiene este badge activo', 409),
                'template_not_found' => self::fail('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404),
                default              => self::fail('VALIDATION_ERROR', 'No se pudo emitir', 422),
            };
        }

        // Notificación con enlace de aceptación, igual que la emisión del panel.
        // Un fallo de correo no deshace la emisión: se registra y se informa.
        try {
            $sent = $service->sendNotification((string) $result['badge_uuid'], (string) $result['accept_token']);
        } catch (\Throwable $e) {
            Logger::app('error', 'API: no se pudo notificar el badge ' . $result['badge_uuid'] . ': ' . $e->getMessage());
            $sent = false;
        }
        return self::ok(['badge_id' => $result['badge_uuid'], 'status' => 'pending', 'notification_sent' => $sent], 201);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function bulkIssue(array $body): array
    {
        $templateUuid = (string) ($body['template_id'] ?? '');
        $earners      = $body['earners'] ?? [];
        if (!is_array($earners) || $earners === []) {
            return self::fail('VALIDATION_ERROR', 'earners requerido (array)', 422);
        }
        if (count($earners) > self::BULK_MAX) {
            return self::fail('VALIDATION_ERROR', 'Máximo ' . self::BULK_MAX . ' earners por request', 422);
        }

        $tpl = BadgeTemplate::findByUuid($templateUuid);
        if ($tpl === null || !$this->companyAllows($tpl)) {
            return self::fail('TEMPLATE_NOT_FOUND', 'Template no existe o no está activo', 404);
        }

        $service = new BadgeService();
        $v       = new Validator();
        $results  = [];
        $messages = []; // índice en $results => correo de aceptación
        foreach ($earners as $i => $earner) {
            try {
                $email     = $v->email((string) ($earner['email'] ?? ''));
                $firstName = $v->name((string) ($earner['first_name'] ?? ''));
                $lastName  = $v->name((string) ($earner['last_name'] ?? ''));
                $r = $service->issue($templateUuid, $email, $firstName, $lastName, (int) $this->apiKey['user_id'], 'api');
            } catch (\InvalidArgumentException $e) {
                $results[] = ['index' => $i, 'ok' => false, 'reason' => $e->getMessage()];
                continue;
            } catch (\Throwable $e) {
                // Fallo de BD en un earner: se registra y se sigue con el resto.
                Logger::app('error', sprintf('API bulk: fallo al emitir el earner #%d: %s', $i, $e->getMessage()));
                $results[] = ['index' => $i, 'ok' => false, 'reason' => 'Error interno al emitir'];
                continue;
            }
            $results[] = ['email' => $email, 'ok' => $r['ok'], 'badge_id' => $r['badge_uuid'], 'reason' => $r['reason'], 'notification_sent' => false];
            if ($r['ok']) {
                try {
                    $msg = $service->buildNotificationMessage((string) $r['badge_uuid'], (string) $r['accept_token']);
                    if ($msg !== null) {
                        $messages[array_key_last($results)] = $msg;
                    }
                } catch (\Throwable $e) {
                    Logger::app('error', 'API bulk: no se pudo armar la notificación del badge ' . $r['badge_uuid'] . ': ' . $e->getMessage());
                }
            }
        }

        // Correos todos juntos con una sola conexión SMTP, como la importación CSV.
        // Un fallo de correo no deshace la emisión.
        if ($messages !== []) {
            try {
                $companyId = isset($tpl['company_id']) ? (int) $tpl['company_id'] : null;
                foreach ((new EmailService())->sendMany($messages, $companyId) as $idx => $ok) {
                    if ($ok) {
                        $service->markNotificationSent($messages[$idx]['badge_id']);
                        $results[$idx]['notification_sent'] = true;
                    }
                }
            } catch (\Throwable $e) {
                Logger::app('error', 'API bulk: fallo al enviar las notificaciones: ' . $e->getMessage());
            }
        }
        return self::ok(['results' => $results]);
    }

    /** @return array<string,mixed> */
    public function getBadge(string $uuid): array
    {
        $b = IssuedBadge::findFullByUuid($uuid);
        if ($b === null || !$this->companyAllows($b)) {
            return self::fail('NOT_FOUND', 'Badge no encontrado', 404);
        }
        return self::ok($this->presentBadge($b));
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function revokeBadge(string $uuid, array $body): array
    {
        $reason = (string) ($body['reason'] ?? 'Revocado vía API');

        $b = IssuedBadge::findFullByUuid($uuid);
        if ($b === null || !$this->companyAllows($b)) {
            return self::fail('NOT_FOUND', 'Badge no encontrado', 404);
        }

        $ok = (new BadgeService())->revoke($uuid, $reason, (int) $this->apiKey['user_id']);
        if (!$ok) {
            return self::fail('NOT_FOUND', 'Badge no encontrado o ya revocado', 404);
        }
        return self::ok(['badge_id' => $uuid, 'status' => 'revoked']);
    }

    /** @return array<string,mixed> */
    public function earnerBadges(string $email): array
    {
        try {
            $email = (new Validator())->email($email);
        } catch (\InvalidArgumentException) {
            return self::fail('VALIDATION_ERROR', 'Email inválido', 422);
        }
        $earner = Earner::findByEmail($email);
        if ($earner === null) {
            return self::fail('NOT_FOUND', 'Earner no encontrado', 404);
        }
        $badges = IssuedBadge::acceptedForEarner((int) $earner['id']);
        // Aislar a la empresa de la API key (la persona puede tener badges de varias).
        if ($this->companyId !== null) {
            $badges = array_values(array_filter(
                $badges,
                fn (array $b): bool => (int) ($b['company_id'] ?? 0) === $this->companyId
            ));
            // Sin badges de esta empresa: igual que un email desconocido (no exponer
            // datos de la persona ni confirmar que existe en otra empresa).
            if ($badges === []) {
                return self::fail('NOT_FOUND', 'Earner no encontrado', 404);
            }
        }
        return self::ok([
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
     * ¿La API key puede operar sobre una entidad de esa empresa?
     *
     * @param array<string,mixed> $entity
     */
    private function companyAllows(array $entity): bool
    {
        $entityCompanyId = isset($entity['company_id']) ? (int) $entity['company_id'] : null;
        return $this->companyId === null || $this->companyId === $entityCompanyId;
    }

    /** @return array{status:int,data:mixed} */
    private static function ok(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'data' => $data];
    }

    /** @return array{status:int,error:array{code:string,message:string}} */
    private static function fail(string $code, string $message, int $status): array
    {
        return ['status' => $status, 'error' => ['code' => $code, 'message' => $message]];
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
