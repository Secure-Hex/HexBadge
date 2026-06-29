<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Autenticación y autorización RBAC (CLAUDE.md §4.1, §2).
 *
 * Roles: superadmin > admin > issuer.
 * En sesión solo se guarda user_id, role y uuid (nada sensible §14).
 */
final class Auth
{
    private const ROLE_HIERARCHY = [
        'issuer'     => 1,
        'admin'      => 2,
        'superadmin' => 3,
    ];

    /**
     * Intenta autenticar por email + contraseña.
     * Devuelve el usuario en éxito o null en fallo (sin distinguir el motivo
     * hacia afuera, para no filtrar si el email existe).
     *
     * @return array<string,mixed>|null
     */
    public static function attempt(string $email, string $password): ?array
    {
        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [strtolower($email)]
        );

        // Verificar siempre el hash (incluso si no hay usuario) para
        // mitigar timing attacks de enumeración de cuentas.
        $hash = is_array($user) ? (string) $user['password_hash'] : '$2y$12$' . str_repeat('.', 53);
        $ok   = password_verify($password, $hash);

        if (!is_array($user) || !$ok) {
            return null;
        }

        return $user;
    }

    /**
     * Establece la sesión autenticada. Regenera el ID y registra el login.
     *
     * @param array<string,mixed> $user
     */
    public static function login(array $user, string $ip): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('user_uuid', (string) $user['uuid']);
        Session::set('user_role', (string) $user['role']);
        Session::set('user_name', (string) $user['name']);
        Session::set('company_id', isset($user['company_id']) && $user['company_id'] !== null ? (int) $user['company_id'] : null);

        Database::getInstance()->update(
            'users',
            ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip],
            'id = ?',
            [(int) $user['id']]
        );
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function check(): bool
    {
        return Session::has('user_id');
    }

    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return is_int($id) ? $id : null;
    }

    public static function role(): ?string
    {
        $role = Session::get('user_role');
        return is_string($role) ? $role : null;
    }

    /**
     * Empresa del usuario en sesión. NULL = superadmin global (sin empresa).
     */
    public static function companyId(): ?int
    {
        $cid = Session::get('company_id');
        return is_int($cid) ? $cid : null;
    }

    public static function isSuperadmin(): bool
    {
        return self::role() === 'superadmin';
    }

    /**
     * Carga el usuario completo desde la base de datos.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        return Database::getInstance()->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * ¿El usuario actual tiene al menos el rol indicado?
     */
    public static function hasRole(string $minRole): bool
    {
        $current = self::role();
        if ($current === null) {
            return false;
        }
        $have = self::ROLE_HIERARCHY[$current] ?? 0;
        $need = self::ROLE_HIERARCHY[$minRole] ?? 99;
        return $have >= $need;
    }

    /**
     * Exige sesión activa; si no, redirige a /login.
     */
    public static function requireAuth(): ?Response
    {
        if (!self::check()) {
            return Response::redirect('/login');
        }
        return null;
    }

    /**
     * Exige un rol mínimo; devuelve una Response de error si no cumple,
     * o null si está autorizado.
     */
    public static function requireRole(string $minRole): ?Response
    {
        if (!self::check()) {
            return Response::redirect('/login');
        }
        if (!self::hasRole($minRole)) {
            return Response::html('<h1>403 — Acceso denegado</h1>', 403);
        }
        return null;
    }
}
