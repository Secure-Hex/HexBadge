<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class BulkImportJob extends Model
{
    protected static string $table = 'bulk_import_jobs';

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
