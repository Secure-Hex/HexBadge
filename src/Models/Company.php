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

    /**
     * Empresas cuyo id está en $ids, ordenadas por nombre. $ids vacío = [].
     *
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function whereIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        return static::db()->fetchAll(
            "SELECT * FROM companies WHERE id IN ($place) ORDER BY name ASC",
            array_values($ids)
        );
    }
}
