<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class BulkImportJob extends Model
{
    protected static string $table = 'bulk_import_jobs';

    /**
     * Job por UUID con la empresa de su template (para el control de acceso).
     *
     * @return array<string,mixed>|null
     */
    public static function findFullByUuid(string $uuid): ?array
    {
        return static::db()->fetchOne(
            'SELECT j.*, bt.company_id, bt.name AS template_name
             FROM bulk_import_jobs j
             JOIN badge_templates bt ON bt.id = j.template_id
             WHERE j.uuid = ? LIMIT 1',
            [$uuid]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        return static::db()->fetchAll(
            'SELECT j.*, bt.name AS template_name
             FROM bulk_import_jobs j
             JOIN badge_templates bt ON bt.id = j.template_id
             WHERE j.user_id = ? ORDER BY j.created_at DESC LIMIT 50',
            [$userId]
        );
    }
}
