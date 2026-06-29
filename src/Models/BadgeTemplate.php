<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class BadgeTemplate extends Model
{
    protected static string $table = 'badge_templates';

    /**
     * Columnas de empresa expuestas con los MISMOS alias que las columnas
     * legacy del template (issuer_*), para que todo el código consumidor siga
     * leyendo $tpl['issuer_name'] sin cambios. COALESCE cae al valor legacy si
     * el template no tuviera empresa.
     */
    private const ISSUER_SELECT = "
        COALESCE(c.name, bt.issuer_name)                      AS issuer_name,
        COALESCE(c.issuer_url, bt.issuer_url)                 AS issuer_url,
        COALESCE(c.issuer_email, bt.issuer_email)             AS issuer_email,
        COALESCE(c.linkedin_org_id, bt.linkedin_org_id)       AS linkedin_org_id,
        c.name AS company_name";

    /**
     * Templates emitibles (estado activo). Si $companyId es null no filtra
     * (superadmin "todas"); si es un id, restringe a esa empresa.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function active(?int $companyId = null): array
    {
        $sql    = "SELECT bt.*, " . self::ISSUER_SELECT . "
                   FROM badge_templates bt
                   LEFT JOIN companies c ON c.id = bt.company_id
                   WHERE bt.state = 'active' AND bt.is_active = 1";
        $params = [];
        if ($companyId !== null) {
            $sql .= ' AND bt.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY bt.name ASC';
        return static::db()->fetchAll($sql, $params);
    }

    /**
     * Lista para el panel admin. Opcionalmente filtra por empresa.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(?int $companyId = null): array
    {
        $sql    = "SELECT bt.*, c.name AS company_name
                   FROM badge_templates bt
                   LEFT JOIN companies c ON c.id = bt.company_id";
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE bt.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY bt.created_at DESC';
        return static::db()->fetchAll($sql, $params);
    }

    /**
     * Override: trae el template con los datos del emisor desde su empresa.
     *
     * @return array<string,mixed>|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        return static::db()->fetchOne(
            "SELECT bt.*, " . self::ISSUER_SELECT . "
             FROM badge_templates bt
             LEFT JOIN companies c ON c.id = bt.company_id
             WHERE bt.uuid = ? LIMIT 1",
            [$uuid]
        );
    }

    /**
     * Incrementa el contador desnormalizado de badges emitidos.
     */
    public static function incrementIssued(int $templateId): void
    {
        static::db()->query(
            'UPDATE badge_templates SET badges_issued = badges_issued + 1 WHERE id = ?',
            [$templateId]
        );
    }

    /**
     * @return array<int,string>
     */
    public static function decodeTags(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $tags = json_decode($json, true);
        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }
}
