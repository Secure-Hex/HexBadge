<?php

declare(strict_types=1);

namespace HexBadge\Models;

use HexBadge\Core\Database;

/**
 * Modelo base. Provee acceso a la BD y finders genéricos sobre la tabla
 * declarada por cada subclase. Todas las consultas son parametrizadas.
 */
abstract class Model
{
    protected static string $table = '';

    protected static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        return static::db()->fetchOne(
            'SELECT * FROM ' . static::table() . ' WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        return static::db()->fetchOne(
            'SELECT * FROM ' . static::table() . ' WHERE uuid = ? LIMIT 1',
            [$uuid]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        return static::db()->insert(static::table(), $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function updateById(int $id, array $data): int
    {
        return static::db()->update(static::table(), $data, 'id = ?', [$id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $orderBy = 'id DESC'): array
    {
        return static::db()->fetchAll('SELECT * FROM ' . static::table() . ' ORDER BY ' . $orderBy);
    }

    private static function table(): string
    {
        if (static::$table === '') {
            throw new \LogicException('El modelo ' . static::class . ' no declaró $table');
        }
        return static::$table;
    }
}
