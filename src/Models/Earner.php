<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class Earner extends Model
{
    protected static string $table = 'earners';

    /**
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->fetchOne(
            'SELECT * FROM earners WHERE email = ? LIMIT 1',
            [strtolower($email)]
        );
    }

    /**
     * Busca un earner por email o lo crea. Devuelve el registro completo.
     *
     * @return array<string,mixed>
     */
    public static function findOrCreate(string $email, string $firstName, string $lastName): array
    {
        $existing = self::findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        $id = self::create([
            'uuid'       => uuid4(),
            'email'      => strtolower($email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);

        $row = self::find($id);
        if ($row === null) {
            throw new \RuntimeException('No se pudo crear el receptor');
        }
        return $row;
    }

    public static function setPassword(int $id, string $password): void
    {
        static::updateById($id, [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'is_verified'   => 1,
        ]);
    }

    public static function hasAccount(array $earner): bool
    {
        return !empty($earner['password_hash']);
    }

    /**
     * Listado paginado con búsqueda opcional por nombre/email.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(string $search = '', ?int $companyId = null, int $limit = 25, int $offset = 0): array
    {
        [$where, $params] = self::searchWhere($search, $companyId);
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        return static::db()->fetchAll(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM issued_badges ib WHERE ib.earner_id = e.id) AS badge_count
             FROM earners e' . $where . " ORDER BY e.created_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function countForAdmin(string $search = '', ?int $companyId = null): int
    {
        [$where, $params] = self::searchWhere($search, $companyId);
        return (int) static::db()->fetchColumn('SELECT COUNT(*) FROM earners e' . $where, $params);
    }

    /**
     * WHERE de búsqueda (nombre/email) + filtro por empresa (receptores con al
     * menos un badge de esa empresa). $companyId null = sin filtro de empresa.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function searchWhere(string $search, ?int $companyId = null): array
    {
        $conds  = [];
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conds[] = '(e.display_name LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($companyId !== null) {
            $conds[] = 'EXISTS (SELECT 1 FROM issued_badges ib
                                JOIN badge_templates bt ON bt.id = ib.badge_template_id
                                WHERE ib.earner_id = e.id AND bt.company_id = ?)';
            $params[] = $companyId;
        }

        $where = $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);
        return [$where, $params];
    }
}
