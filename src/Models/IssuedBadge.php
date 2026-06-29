<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class IssuedBadge extends Model
{
    protected static string $table = 'issued_badges';

    /**
     * ¿El earner ya tiene un badge activo (no revocado/rechazado) de este template?
     */
    public static function hasActiveDuplicate(int $templateId, int $earnerId): bool
    {
        $count = (int) static::db()->fetchColumn(
            "SELECT COUNT(*) FROM issued_badges
             WHERE badge_template_id = ? AND earner_id = ?
               AND status IN ('pending','accepted')",
            [$templateId, $earnerId]
        );
        return $count > 0;
    }

    /**
     * Badge con datos de template y earner unidos, por UUID (verificación).
     *
     * @return array<string,mixed>|null
     */
    public static function findFullByUuid(string $uuid): ?array
    {
        return static::db()->fetchOne(
            'SELECT ib.*,
                    bt.uuid AS template_uuid, bt.name AS template_name, bt.description AS template_description,
                    bt.image_filename, bt.criteria_text, bt.criteria_url, bt.skills_tags, bt.company_id,
                    COALESCE(c.name, bt.issuer_name)                AS issuer_name,
                    COALESCE(c.issuer_url, bt.issuer_url)           AS issuer_url,
                    COALESCE(c.issuer_email, bt.issuer_email)       AS issuer_email,
                    COALESCE(c.linkedin_org_id, bt.linkedin_org_id) AS linkedin_org_id,
                    c.name AS company_name,
                    bt.certificate_filename, bt.certificate_config, bt.updated_at AS template_updated_at,
                    e.uuid AS earner_uuid, e.email AS earner_email,
                    e.first_name, e.last_name, e.display_name
             FROM issued_badges ib
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             JOIN earners e ON e.id = ib.earner_id
             LEFT JOIN companies c ON c.id = bt.company_id
             WHERE ib.uuid = ? LIMIT 1',
            [$uuid]
        );
    }

    /**
     * Badge por accept_token (flujo de aceptación del earner).
     *
     * @return array<string,mixed>|null
     */
    public static function findByAcceptToken(string $tokenHash): ?array
    {
        return static::db()->fetchOne(
            'SELECT ib.*, e.uuid AS earner_uuid, e.first_name, e.last_name, e.email AS earner_email,
                    bt.name AS template_name, bt.image_filename
             FROM issued_badges ib
             JOIN earners e ON e.id = ib.earner_id
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             WHERE ib.accept_token = ? LIMIT 1',
            [$tokenHash]
        );
    }

    /**
     * Badges aceptados de un earner (wallet pública).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function acceptedForEarner(int $earnerId): array
    {
        return static::db()->fetchAll(
            "SELECT ib.uuid, ib.issued_at, ib.expires_at, ib.status,
                    bt.name AS template_name, bt.description AS template_description,
                    bt.image_filename, bt.skills_tags, bt.company_id,
                    COALESCE(c.name, bt.issuer_name) AS issuer_name
             FROM issued_badges ib
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             LEFT JOIN companies c ON c.id = bt.company_id
             WHERE ib.earner_id = ? AND ib.status = 'accepted'
             ORDER BY ib.issued_at DESC",
            [$earnerId]
        );
    }

    /**
     * Badges pendientes de aceptación de un earner (vista privada del dueño).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pendingForEarner(int $earnerId): array
    {
        return static::db()->fetchAll(
            "SELECT ib.uuid, ib.issued_at, ib.expires_at,
                    bt.name AS template_name, bt.image_filename, bt.issuer_name
             FROM issued_badges ib
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             WHERE ib.earner_id = ? AND ib.status = 'pending'
             ORDER BY ib.issued_at DESC",
            [$earnerId]
        );
    }

    /**
     * Listado para el panel admin con filtros opcionales.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(array $filters = [], int $limit = 1000, int $offset = 0): array
    {
        [$where, $params] = self::adminWhere($filters);
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $sql = "SELECT ib.uuid, ib.status, ib.issued_at, ib.issued_via, ib.expires_at,
                       bt.name AS template_name, bt.company_id, co.name AS company_name,
                       e.display_name AS earner_name, e.email AS earner_email
                FROM issued_badges ib
                JOIN badge_templates bt ON bt.id = ib.badge_template_id
                JOIN earners e ON e.id = ib.earner_id
                LEFT JOIN companies co ON co.id = bt.company_id"
              . $where
              . " ORDER BY ib.issued_at DESC LIMIT {$limit} OFFSET {$offset}";
        return static::db()->fetchAll($sql, $params);
    }

    /**
     * Cuenta total de badges para los mismos filtros (para la paginación).
     *
     * @param array<string,mixed> $filters
     */
    public static function countForAdmin(array $filters = []): int
    {
        [$where, $params] = self::adminWhere($filters);
        // Incluye los joins porque la búsqueda 'q' filtra por earner/template.
        $sql = 'SELECT COUNT(*) FROM issued_badges ib
                JOIN badge_templates bt ON bt.id = ib.badge_template_id
                JOIN earners e ON e.id = ib.earner_id' . $where;
        return (int) static::db()->fetchColumn($sql, $params);
    }

    /**
     * Construye el WHERE compartido entre listado y conteo.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $sql    = ' WHERE 1=1';
        $params = [];
        if (!empty($filters['company_id'])) {
            $sql .= ' AND bt.company_id = ?';
            $params[] = (int) $filters['company_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND ib.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['template_id'])) {
            $sql .= ' AND ib.badge_template_id = ?';
            $params[] = (int) $filters['template_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND ib.issued_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND ib.issued_at <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . trim((string) $filters['q']) . '%';
            $sql .= ' AND (e.display_name LIKE ? OR e.email LIKE ? OR bt.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        return [$sql, $params];
    }
}
