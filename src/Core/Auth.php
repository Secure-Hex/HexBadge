<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Autenticación y autorización RBAC (CLAUDE.md §4.1, §2).
 *
 * Roles: superadmin > admin > issuer.
 * En sesión solo se guarda user_id, role y uuid (nada sensible §14).
 * Las sesiones abiertas con Hermes guardan además {sub, sid, logged_in_at}: cada
 * request verifica contra la BD que el back-channel logout no las haya cerrado.
 */
final class Auth
{
    private const ROLE_HIERARCHY = [
        'issuer'     => 1,
        'admin'      => 2,
        'superadmin' => 3,
    ];

    /** Absolute session lifetime, on top of Session's idle timeout. */
    public const ABSOLUTE_LIFETIME_SECONDS = 43200;

    public const SESSION_OK = 'ok';
    public const SESSION_EXPIRED = 'expired';
    public const SESSION_REVOKED = 'revoked';
    public const SESSION_INACTIVE = 'inactive';
    public const SESSION_LOCAL_DISABLED = 'local_disabled';

    /** Whether this request already validated the session (check() runs many times per request). */
    private static bool $verified = false;

    /**
     * Break-glass switch (ADMIN_LOCAL_LOGIN): password login, password reset, 2FA and invitations.
     */
    public static function localLoginEnabled(): bool
    {
        return (bool) config('hermes.local_login', false);
    }

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
     * @param array{sub:string,sid:string}|null $hermes the Hermes SSO session behind this login (null = local break-glass login)
     */
    public static function login(array $user, string $ip, ?array $hermes = null): void
    {
        Session::regenerate();
        Session::set('auth_at', time());
        if ($hermes !== null) {
            // The sid is what Hermes' back-channel logout names; the login time scopes sub-wide logouts.
            Session::set('oidc', $hermes + ['logged_in_at' => date('Y-m-d H:i:s')]);
        } else {
            Session::remove('oidc');
        }
        self::$verified = true;
        Session::set('user_id', (int) $user['id']);
        Session::set('user_uuid', (string) $user['uuid']);
        Session::set('user_role', (string) $user['role']);
        Session::set('user_name', (string) $user['name']);
        Session::set('company_id', isset($user['company_id']) && $user['company_id'] !== null ? (int) $user['company_id'] : null);
        Session::set('company_ids', \HexBadge\Models\User::companyIds((int) $user['id']));

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
        self::$verified = false;
    }

    /**
     * Whether a user is logged in. The first call of each request also enforces the absolute
     * lifetime, Hermes back-channel logouts, local deactivation (is_active) and the break-glass
     * flag; a session that fails any of them is cleared.
     */
    public static function check(): bool
    {
        if (!Session::has('user_id')) {
            return false;
        }
        if (self::$verified) {
            return true;
        }
        self::$verified = true;

        $oidc    = self::hermesSession($_SESSION);
        $state   = self::sessionState((int) Session::get('user_id'), $oidc['sid'] ?? null, $oidc['sub'] ?? null, $oidc['logged_in_at'] ?? null);
        $outcome = self::sessionDecision($_SESSION, time(), $state, self::localLoginEnabled());
        if ($outcome === self::SESSION_OK) {
            return true;
        }

        $_SESSION = [];
        Session::regenerate();
        $message = match ($outcome) {
            self::SESSION_EXPIRED  => 'Tu sesión expiró. Vuelve a iniciar sesión.',
            self::SESSION_REVOKED  => 'Tu sesión fue cerrada desde Hermes.',
            self::SESSION_INACTIVE => 'Tu cuenta está desactivada en HexBadge.',
            default                => null,
        };
        if ($message !== null) {
            Session::flash('error', $message);
        }
        return false;
    }

    /**
     * The per-request session decision, without I/O. $state comes from sessionState()
     * (null when the user row is gone).
     *
     * @param array<string,mixed> $session
     * @param array{is_active:int,revoked:int}|null $state
     */
    public static function sessionDecision(array $session, int $now, ?array $state, bool $localLoginEnabled): string
    {
        $authAt = $session['auth_at'] ?? null;
        if (!is_int($authAt) || $now - $authAt > self::ABSOLUTE_LIFETIME_SECONDS) {
            return self::SESSION_EXPIRED;
        }
        if (self::hermesSession($session) === null && !$localLoginEnabled) {
            return self::SESSION_LOCAL_DISABLED;
        }
        if ($state !== null && $state['revoked'] === 1) {
            return self::SESSION_REVOKED;
        }
        if ($state === null || $state['is_active'] !== 1) {
            return self::SESSION_INACTIVE;
        }
        return self::SESSION_OK;
    }

    /**
     * One indexed query: whether the user is still active and whether a back-channel logout
     * ended this Hermes session (by sid, or sub-wide when the logout carried no sid).
     *
     * @return array{is_active:int,revoked:int}|null
     */
    private static function sessionState(int $userId, ?string $sid, ?string $sub, ?string $loggedInAt): ?array
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT u.is_active,
                    EXISTS (
                        SELECT 1 FROM oidc_backchannel_logouts l
                        WHERE l.sid = ? OR (l.sid IS NULL AND l.sub = ? AND l.created_at >= ?)
                    ) AS revoked
             FROM users u WHERE u.id = ?',
            [$sid, $sub, $loggedInAt, $userId]
        );
        return $row === null ? null : ['is_active' => (int) $row['is_active'], 'revoked' => (int) $row['revoked']];
    }

    /**
     * @param array<string,mixed> $session
     * @return array{sub:string,sid:string,logged_in_at:string}|null
     */
    private static function hermesSession(array $session): ?array
    {
        $oidc = $session['oidc'] ?? null;
        return is_array($oidc) && is_string($oidc['sid'] ?? null) && is_string($oidc['sub'] ?? null) && is_string($oidc['logged_in_at'] ?? null)
            ? $oidc : null;
    }

    /**
     * issuer/admin provisioned from Hermes before a superadmin assigned them a company.
     */
    public static function awaitingCompany(): bool
    {
        return self::check() && !self::isSuperadmin() && self::companyIds() === [];
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

    /**
     * Todas las empresas a las que el usuario tiene acceso (para el switcher y
     * las validaciones de acceso). Superadmin devuelve [] (es global, sin filtro).
     * Fallback para sesiones abiertas antes de la migración: la primaria sola.
     *
     * @return array<int,int>
     */
    public static function companyIds(): array
    {
        $ids = Session::get('company_ids');
        if (is_array($ids)) {
            return array_map('intval', $ids);
        }
        $cid = self::companyId();
        return $cid !== null ? [$cid] : [];
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
