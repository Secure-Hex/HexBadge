<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Log de auditoría (CLAUDE.md §4.9).
 *
 * - Inserta en audit_logs (solo-inserción; nunca update/delete).
 * - NO registra contraseñas, tokens, API keys ni datos personales en claro.
 * - También escribe un log de aplicación a archivo con permisos 640.
 */
final class Logger
{
    /**
     * Registra una acción auditable en la base de datos.
     *
     * @param array<string,mixed> $metadata Datos no sensibles del evento.
     */
    public static function audit(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $apiKeyId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            // Empresa del actor (multitenancy): de la sesión web, o del metadata
            // (cuando lo provee la API, que no tiene sesión).
            $companyId = Auth::companyId();
            if ($companyId === null && isset($metadata['company_id'])) {
                $companyId = (int) $metadata['company_id'];
            }
            Database::getInstance()->insert('audit_logs', [
                'user_id'     => $userId,
                'company_id'  => $companyId,
                'api_key_id'  => $apiKeyId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'ip_address'  => $ip ?? '0.0.0.0',
                'user_agent'  => $userAgent,
                'metadata'    => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // La auditoría nunca debe romper el flujo principal; solo loggear.
            self::app('error', 'Fallo al registrar auditoría: ' . $e->getMessage());
        }
    }

    /**
     * Log de aplicación a archivo.
     */
    public static function app(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s%s', date('c'), strtoupper($level), $message, PHP_EOL);
        $path = BASE_PATH . '/storage/logs/app.log';

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        if (is_file($path)) {
            @chmod($path, 0640);
        }
    }
}
