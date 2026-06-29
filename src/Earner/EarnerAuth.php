<?php

declare(strict_types=1);

namespace HexBadge\Earner;

use HexBadge\Core\Session;
use HexBadge\Models\Earner;

/**
 * Autenticación de receptores (earners) en el portal público.
 *
 * Sesión independiente de la del panel admin (otro nombre de cookie). En
 * la sesión solo se guarda el id y uuid del earner.
 */
final class EarnerAuth
{
    /**
     * Verifica credenciales. Devuelve el earner o null.
     *
     * @return array<string,mixed>|null
     */
    public static function attempt(string $email, string $password): ?array
    {
        $earner = Earner::findByEmail($email);
        $hash   = is_array($earner) && !empty($earner['password_hash'])
            ? (string) $earner['password_hash']
            : '$2y$12$' . str_repeat('.', 53);

        $ok = password_verify($password, $hash);
        if (!is_array($earner) || empty($earner['password_hash']) || !$ok) {
            return null;
        }
        return $earner;
    }

    /**
     * @param array<string,mixed> $earner
     */
    public static function login(array $earner): void
    {
        Session::regenerate();
        Session::set('earner_id', (int) $earner['id']);
        Session::set('earner_uuid', (string) $earner['uuid']);
        Session::set('earner_name', (string) $earner['display_name']);
    }

    public static function check(): bool
    {
        return Session::has('earner_id');
    }

    public static function id(): ?int
    {
        $id = Session::get('earner_id');
        return is_int($id) ? $id : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $id = self::id();
        return $id === null ? null : Earner::find($id);
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
