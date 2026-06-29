<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Rate limiting por IP/usuario (CLAUDE.md §4.6).
 *
 * Límites por defecto:
 *  - Login:  5 intentos / 15 min por IP
 *  - API:    100 requests / min por API key
 *  - Verify: 30 requests / min por IP
 *  - CSV:    3 uploads / hora por usuario
 */
final class RateLimiter
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Devuelve true si la acción está permitida (y registra el intento);
     * false si se superó el límite.
     */
    public function check(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        $count = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM rate_limit_attempts
             WHERE identifier = ? AND action = ? AND attempted_at > ?',
            [$identifier, $action, $since]
        );

        if ($count >= $maxAttempts) {
            return false;
        }

        $this->db->insert('rate_limit_attempts', [
            'identifier' => $identifier,
            'action'     => $action,
        ]);

        return true;
    }

    /**
     * Cuenta intentos restantes en la ventana sin registrar uno nuevo.
     */
    public function remaining(string $identifier, string $action, int $maxAttempts, int $windowSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM rate_limit_attempts
             WHERE identifier = ? AND action = ? AND attempted_at > ?',
            [$identifier, $action, $since]
        );
        return max(0, $maxAttempts - $count);
    }

    /**
     * Limpia los intentos de un identificador/acción (ej: tras login OK).
     */
    public function clear(string $identifier, string $action): void
    {
        $this->db->query(
            'DELETE FROM rate_limit_attempts WHERE identifier = ? AND action = ?',
            [$identifier, $action]
        );
    }

    /**
     * Purga intentos antiguos (mantenimiento; llamar desde cron/CLI).
     */
    public function purgeOlderThan(int $seconds = 86400): void
    {
        $before = date('Y-m-d H:i:s', time() - $seconds);
        $this->db->query('DELETE FROM rate_limit_attempts WHERE attempted_at < ?', [$before]);
    }
}
