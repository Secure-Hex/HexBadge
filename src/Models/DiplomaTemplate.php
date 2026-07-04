<?php

declare(strict_types=1);

namespace HexBadge\Models;

/**
 * Plantilla de diploma reutilizable. Una empresa puede guardar varias y
 * referenciarlas desde sus acreditaciones (badge_templates.certificate_template_id).
 * El formato de `config` es idéntico al certificate_config de un template.
 */
final class DiplomaTemplate extends Model
{
    protected static string $table = 'diploma_templates';

    /**
     * Listado para el panel. Superadmin ($companyId null) ve todas; un sub-admin
     * solo las de su empresa.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(?int $companyId = null): array
    {
        if ($companyId !== null) {
            return static::db()->fetchAll(
                'SELECT dt.*, co.name AS company_name
                 FROM diploma_templates dt
                 LEFT JOIN companies co ON co.id = dt.company_id
                 WHERE dt.company_id = ? ORDER BY dt.name ASC',
                [$companyId]
            );
        }
        return static::db()->fetchAll(
            'SELECT dt.*, co.name AS company_name
             FROM diploma_templates dt
             LEFT JOIN companies co ON co.id = dt.company_id
             ORDER BY dt.name ASC'
        );
    }

    /** ¿La plantilla está lista para usarse (imagen + posiciones requeridas)? */
    public static function isConfigured(array $row): bool
    {
        return !empty($row['image_filename']) && !empty($row['config']);
    }

    /** Cuántas acreditaciones referencian esta plantilla (para bloquear borrado). */
    public static function usageCount(int $id): int
    {
        return (int) static::db()->fetchColumn(
            'SELECT COUNT(*) FROM badge_templates WHERE certificate_template_id = ?',
            [$id]
        );
    }
}
