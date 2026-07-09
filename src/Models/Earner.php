<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class Earner extends Model
{
    protected static string $table = 'earners';

    /**
     * Resuelve el earner dueño de un correo (primario o secundario) vía la
     * tabla earner_emails. Ignora cuentas fusionadas (merged_into_id), cuyo
     * correo ya resuelve al earner destino a través de earner_emails.
     *
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->fetchOne(
            'SELECT e.* FROM earners e
             JOIN earner_emails ee ON ee.earner_id = e.id
             WHERE ee.email = ? AND e.merged_into_id IS NULL
             LIMIT 1',
            [strtolower($email)]
        );
    }

    /**
     * Busca un earner por email o lo crea. Devuelve el registro completo. Al
     * crear, registra el correo como primario en earner_emails (atómico).
     *
     * @return array<string,mixed>
     */
    public static function findOrCreate(string $email, string $firstName, string $lastName): array
    {
        $existing = self::findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        $db    = static::db();
        $email = strtolower($email);
        $db->beginTransaction();
        try {
            $id = self::create([
                'uuid'       => uuid4(),
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ]);
            $db->insert('earner_emails', [
                'earner_id'  => $id,
                'email'      => $email,
                'is_primary' => 1,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

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
     * Búsqueda pública de personas por nombre o email. Solo devuelve a quienes
     * tienen al menos un badge aceptado (presencia pública real). Para mostrar
     * en el directorio: uuid, nombre y foto. No expone el email.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function searchPublic(string $query, int $limit = 40): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like  = '%' . $query . '%';
        $limit = max(1, min(100, $limit));
        return static::db()->fetchAll(
            "SELECT e.uuid, e.display_name, e.avatar_filename
             FROM earners e
             WHERE (e.display_name LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)
               AND EXISTS (SELECT 1 FROM issued_badges ib WHERE ib.earner_id = e.id AND ib.status = 'accepted')
             ORDER BY e.display_name ASC LIMIT {$limit}",
            [$like, $like, $like, $like]
        );
    }

    /**
     * Listado paginado con búsqueda opcional por nombre/email.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(string $search = '', ?int $companyId = null, int $limit = 25, int $offset = 0, string $sort = 'reciente', string $dir = 'desc'): array
    {
        [$where, $params] = self::searchWhere($search, $companyId);
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $orderBy = self::orderBy($sort, $dir);
        return static::db()->fetchAll(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM issued_badges ib WHERE ib.earner_id = e.id) AS badge_count
             FROM earners e' . $where . " ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /**
     * Traduce (sort, dir) del request a un ORDER BY seguro por whitelist (nunca
     * input crudo → sin SQL injection). Desempate por e.id para orden estable.
     */
    private static function orderBy(string $sort, string $dir): string
    {
        $cols = [
            'nombre'     => 'e.display_name',
            'email'      => 'e.email',
            'badges'     => 'badge_count',
            'verificado' => 'e.is_verified',
            'reciente'   => 'e.created_at',
        ];
        $col = $cols[$sort] ?? 'e.created_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return "{$col} {$dir}, e.id DESC";
    }

    /**
     * Volcado completo para exportar: una fila por receptor con sus badges
     * agregados. Superadmin (companyId null) ve a todos; un sub-admin solo a
     * quienes tienen algún badge de su empresa (y solo esos badges).
     *
     * ponytail: GROUP_CONCAT trunca a group_concat_max_len (1024 por defecto).
     * Subir esa variable de sesión si algún receptor tiene decenas de badges.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function exportForAdmin(?int $companyId = null): array
    {
        $btOn   = 'bt.id = ib.badge_template_id';
        $where  = '';
        $params = [];
        if ($companyId !== null) {
            $btOn    .= ' AND bt.company_id = ?';
            $params[] = $companyId;                         // param del JOIN va primero
            $where    = ' WHERE EXISTS (SELECT 1 FROM issued_badges ib2
                            JOIN badge_templates bt2 ON bt2.id = ib2.badge_template_id
                            WHERE ib2.earner_id = e.id AND bt2.company_id = ?)';
            $params[] = $companyId;
        }
        return static::db()->fetchAll(
            "SELECT e.display_name, e.first_name, e.last_name, e.email, e.is_verified,
                    GROUP_CONCAT(DISTINCT bt.name ORDER BY bt.name SEPARATOR '; ') AS badges
             FROM earners e
             LEFT JOIN issued_badges ib ON ib.earner_id = e.id
             LEFT JOIN badge_templates bt ON {$btOn}
             {$where}
             GROUP BY e.id
             ORDER BY e.display_name ASC",
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
