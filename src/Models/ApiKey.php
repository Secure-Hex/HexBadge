<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class ApiKey extends Model
{
    protected static string $table = 'api_keys';

    /**
     * @return array<string,mixed>|null
     */
    public static function findByHash(string $hash): ?array
    {
        return static::db()->fetchOne(
            'SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1 LIMIT 1',
            [$hash]
        );
    }

    public static function touch(int $id): void
    {
        static::db()->update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        return static::db()->fetchAll(
            'SELECT id, key_prefix, name, scopes, last_used_at, expires_at, is_active, created_at
             FROM api_keys WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
    }
}
