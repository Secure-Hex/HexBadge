<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class UserInvitation extends Model
{
    protected static string $table = 'user_invitations';

    /**
     * @return array<string,mixed>|null
     */
    public static function findByTokenHash(string $tokenHash): ?array
    {
        return static::db()->fetchOne(
            "SELECT * FROM user_invitations WHERE token_hash = ? LIMIT 1",
            [$tokenHash]
        );
    }

    /**
     * ¿Hay una invitación pendiente (no aceptada, no expirada) para ese email?
     */
    public static function hasPending(string $email): bool
    {
        $count = (int) static::db()->fetchColumn(
            "SELECT COUNT(*) FROM user_invitations
             WHERE email = ? AND accepted_at IS NULL AND expires_at > NOW()",
            [strtolower($email)]
        );
        return $count > 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listForAdmin(?int $companyId = null): array
    {
        $sql    = "SELECT i.id, i.email, i.role, i.company_id, i.expires_at, i.accepted_at, i.created_at,
                          u.name AS invited_by_name, c.name AS company_name
                   FROM user_invitations i
                   LEFT JOIN users u ON u.id = i.invited_by
                   LEFT JOIN companies c ON c.id = i.company_id";
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE i.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY i.created_at DESC LIMIT 100';
        return static::db()->fetchAll($sql, $params);
    }
}
