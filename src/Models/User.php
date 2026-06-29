<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class User extends Model
{
    protected static string $table = 'users';

    /**
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->fetchOne(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [strtolower($email)]
        );
    }

    /**
     * Lista de usuarios, opcionalmente filtrada por empresa.
     * $companyId null = sin filtro (todos, incluidos superadmins).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allOrdered(?int $companyId = null): array
    {
        $sql    = 'SELECT u.id, u.uuid, u.name, u.email, u.role, u.company_id, u.is_active,
                          u.last_login_at, u.created_at, c.name AS company_name
                   FROM users u
                   LEFT JOIN companies c ON c.id = u.company_id';
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE u.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY u.created_at DESC';
        return static::db()->fetchAll($sql, $params);
    }
}
