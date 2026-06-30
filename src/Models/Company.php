<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class Company extends Model
{
    protected static string $table = 'companies';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function allOrdered(): array
    {
        return static::db()->fetchAll('SELECT * FROM companies ORDER BY name ASC');
    }
}
