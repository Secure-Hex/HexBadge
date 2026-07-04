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

    /**
     * Ids de las empresas a las que el usuario tiene acceso (pivote user_companies).
     * La primaria (users.company_id) va primero.
     *
     * @return array<int,int>
     */
    public static function companyIds(int $userId): array
    {
        $rows = static::db()->fetchAll(
            'SELECT uc.company_id
             FROM user_companies uc
             JOIN users u ON u.id = uc.user_id
             WHERE uc.user_id = ?
             ORDER BY (uc.company_id = u.company_id) DESC, uc.company_id ASC',
            [$userId]
        );
        return array_map(static fn ($r): int => (int) $r['company_id'], $rows);
    }

    /**
     * Reemplaza el conjunto de empresas del usuario y fija la primaria
     * (users.company_id) a la primera del set. Set vacío = sin empresas
     * (deja company_id como estaba: solo aplica a superadmin, que no usa el pivote).
     *
     * @param array<int,int> $companyIds
     */
    public static function setCompanies(int $userId, array $companyIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $companyIds)));
        $db  = static::db();
        $db->beginTransaction();
        try {
            $db->query('DELETE FROM user_companies WHERE user_id = ?', [$userId]);
            foreach ($ids as $cid) {
                $db->insert('user_companies', ['user_id' => $userId, 'company_id' => $cid]);
            }
            if ($ids !== []) {
                static::updateById($userId, ['company_id' => $ids[0]]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
