<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\CSRF;
use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Core\Oidc;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\User;
use HexBadge\Services\HermesClient;
use HexBadge\Services\PasswordResetService;
use PDOException;
use Throwable;

/**
 * Login / logout (CLAUDE.md §4.1, §6).
 *
 * El login normal es con Hermes (OpenID Connect, Authorization Code + PKCE). El login
 * local con contraseña queda como acceso de emergencia (ADMIN_LOCAL_LOGIN, solo superadmin
 * con 2FA). Sus rutas solo se registran con el flag activo (src/Admin/routes.php).
 */
final class AuthController extends Controller
{
    private const PENDING_LOGIN_TTL_SECONDS = 600;
    /** Slack on top of the absolute session lifetime before a back-channel row is purged. */
    private const BACKCHANNEL_RETENTION_MARGIN_SECONDS = 86400;
    /** Back-channel rows must outlive every session they can end. */
    private const BACKCHANNEL_RETENTION_SECONDS = Auth::ABSOLUTE_LIFETIME_SECONDS + self::BACKCHANNEL_RETENTION_MARGIN_SECONDS;
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000'; // duplicate key
    private const SQLSTATE_SERIALIZATION_FAILURE = '40001'; // deadlock
    private const LOGIN_FAILED = 'No fue posible iniciar sesión con Hermes. Vuelve a intentarlo.';
    private const HERMES_UNAVAILABLE = 'No fue posible contactar a Hermes. Intenta nuevamente en unos minutos.';
    private const EMAIL_CONFLICT = 'Tu correo ya está asociado a otra cuenta de HexBadge. Contacta a un administrador.';
    private const INVALID_CREDENTIALS = 'Credenciales inválidas';

    private ?HermesClient $hermes;

    /** The router builds controllers without arguments; tests inject a HermesClient with a fake fetch. */
    public function __construct(?HermesClient $hermes = null)
    {
        $this->hermes = $hermes;
    }

    private function hermes(): HermesClient
    {
        return $this->hermes ??= new HermesClient();
    }

    /**
     * GET /login — botón de Hermes (y el formulario de emergencia si está habilitado).
     */
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return $this->redirect('/admin');
        }
        return $this->view('auth/login', [
            'pageTitle'  => 'Ingresar',
            'reset'      => Auth::localLoginEnabled() && $request->query('reset') === '1',
            'localLogin' => Auth::localLoginEnabled(),
            'configured' => $this->hermes()->isConfigured(),
        ]);
    }

    /**
     * GET /forgot-password — formulario para pedir el enlace de recuperación.
     */
    public function showForgot(Request $request): Response
    {
        if (Auth::check()) {
            return $this->redirect('/admin');
        }
        return $this->view('auth/forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => false, 'error' => null]);
    }

    /**
     * POST /forgot-password — genera y envía el enlace (respuesta genérica,
     * no revela si el email existe).
     */
    public function sendReset(Request $request): Response
    {
        $this->verifyCsrf($request);

        $ip      = $request->ip();
        $limiter = new RateLimiter();
        if (!$limiter->check($ip, 'pwreset', 5, 900)) {
            return $this->view('auth/forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.'], 429);
        }

        try {
            $email = (new Validator())->email((string) $request->input('email', ''));
            (new PasswordResetService())->request('admin', $email);
        } catch (\InvalidArgumentException) {
            // Email inválido: igual mostramos el mensaje genérico (anti-enumeración).
        }
        return $this->view('auth/forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => true, 'error' => null]);
    }

    /**
     * GET /reset-password/{token} — formulario de nueva contraseña.
     */
    public function showReset(Request $request, string $token): Response
    {
        $valid = (new PasswordResetService())->findValid('admin', $token) !== null;
        return $this->view('auth/reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => $valid, 'errors' => []]);
    }

    /**
     * POST /reset-password/{token} — aplica la nueva contraseña.
     */
    public function reset(Request $request, string $token): Response
    {
        $this->verifyCsrf($request);

        $svc = new PasswordResetService();
        if ($svc->findValid('admin', $token) === null) {
            return $this->view('auth/reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => false, 'errors' => []], 410);
        }
        try {
            $pw = (new Validator())->password((string) $request->input('password', ''));
            if (!hash_equals($pw, (string) $request->input('password_confirm', ''))) {
                throw new \InvalidArgumentException('Las contraseñas no coinciden.');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->view('auth/reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => true, 'errors' => [$e->getMessage()]], 422);
        }
        $svc->reset('admin', $token, $pw, $request->ip());
        Logger::audit('user.password.reset', null, null, null, [], null, $request->ip(), $request->userAgent());
        return $this->redirect('/login?reset=1');
    }

    /**
     * POST /login — procesa credenciales con rate limiting.
     */
    public function login(Request $request): Response
    {
        // ponytail: login sin CSRF a pedido; el resto de POST sigue protegido.
        $ip      = $request->ip();
        $limiter = new RateLimiter();

        $maxAttempts = (int) config('rate_limit.login', 5);
        $window      = (int) config('rate_limit.login_window', 900);

        if (!$limiter->check($ip, 'login', $maxAttempts, $window)) {
            Logger::audit('user.login.rate_limited', null, null, null, [], null, $ip, $request->userAgent());
            return $this->loginView('Demasiados intentos. Intenta nuevamente en unos minutos.', '', 429);
        }

        $emailRaw = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        try {
            $validator = new Validator();
            $email     = $validator->email($emailRaw);
        } catch (\InvalidArgumentException) {
            return $this->loginView(self::INVALID_CREDENTIALS, $emailRaw);
        }

        $user = Auth::attempt($email, $password);

        if ($user === null) {
            Logger::audit('user.login.failed', null, null, null, ['email' => $email], null, $ip, $request->userAgent());
            return $this->loginView(self::INVALID_CREDENTIALS, $emailRaw);
        }

        // Break-glass: con contraseña solo entran superadmins con 2FA; el resto usa Hermes.
        $denied = match (true) {
            $user['role'] !== 'superadmin'            => 'not_superadmin',
            (int) ($user['totp_enabled'] ?? 0) !== 1 => 'totp_required',
            default                                   => null,
        };
        if ($denied !== null) {
            Logger::audit('user.login.local_denied', (int) $user['id'], 'user', (string) $user['uuid'], ['method' => 'local', 'reason' => $denied], null, $ip, $request->userAgent());
            return $this->loginView(self::INVALID_CREDENTIALS, $emailRaw);
        }

        $limiter->clear($ip, 'login');

        // No abrimos sesión todavía: guardamos el id pendiente y pedimos el código TOTP.
        Session::set('pending_2fa_user_id', (int) $user['id']);
        return $this->redirect('/login/2fa');
    }

    /**
     * GET /login/2fa — formulario del segundo factor.
     */
    public function showTwoFactor(Request $request): Response
    {
        if (!Session::has('pending_2fa_user_id')) {
            return $this->redirect('/login');
        }
        return $this->view('auth/two_factor', ['pageTitle' => 'Verificación 2FA', 'error' => null]);
    }

    /**
     * POST /login/2fa — verifica el código TOTP y completa el login.
     */
    public function twoFactor(Request $request): Response
    {
        $this->verifyCsrf($request);

        $userId = Session::get('pending_2fa_user_id');
        if (!is_int($userId)) {
            return $this->redirect('/login');
        }

        $ip      = $request->ip();
        $limiter = new RateLimiter();
        if (!$limiter->check($ip, '2fa', 5, 900)) {
            return $this->view('auth/two_factor', ['pageTitle' => 'Verificación 2FA', 'error' => 'Demasiados intentos. Esperá unos minutos.'], 429);
        }

        $user = \HexBadge\Models\User::find($userId);
        if ($user === null || (int) $user['totp_enabled'] !== 1 || $user['role'] !== 'superadmin' || (int) $user['is_active'] !== 1) {
            Session::remove('pending_2fa_user_id');
            return $this->redirect('/login');
        }

        if (!\HexBadge\Core\Totp::verify((string) $user['totp_secret'], (string) $request->input('code', ''))) {
            Logger::audit('user.2fa.failed', (int) $user['id'], 'user', (string) $user['uuid'], [], null, $ip, $request->userAgent());
            return $this->view('auth/two_factor', ['pageTitle' => 'Verificación 2FA', 'error' => 'Código incorrecto.'], 401);
        }

        $limiter->clear($ip, '2fa');
        Session::remove('pending_2fa_user_id');
        Auth::login($user, $ip);
        Logger::audit('user.login', (int) $user['id'], 'user', (string) $user['uuid'], ['method' => 'local', '2fa' => true], null, $ip, $request->userAgent());
        return $this->redirect('/admin');
    }

    /**
     * POST /logout — cierra solo la sesión de HexBadge (la de Hermes sigue abierta a propósito).
     * Sin CSRF válido igual cierra: lo peor que logra un logout forjado es un logout.
     */
    public function logout(Request $request): Response
    {
        $userId = Auth::id();
        if ($userId !== null) {
            $details = CSRF::verify($request->input('_csrf')) ? [] : ['csrf' => 'invalid'];
            Logger::audit('user.logout', $userId, null, null, $details, null, $request->ip(), $request->userAgent());
        }
        Auth::logout();
        return $this->redirect('/login');
    }

    /**
     * GET /auth/hermes/login — redirige a /authorize de Hermes con state, nonce y PKCE S256.
     */
    public function redirectToHermes(Request $request): Response
    {
        if (Auth::check()) {
            return $this->redirect('/admin');
        }

        $hermes = $this->hermes();
        if (!$hermes->isConfigured()) {
            Session::flash('error', 'El inicio de sesión con Hermes no está configurado.');
            return $this->redirect('/login');
        }

        try {
            $authorizationEndpoint = (string) $hermes->discovery()['authorization_endpoint'];
        } catch (Throwable) {
            Session::flash('error', self::HERMES_UNAVAILABLE);
            return $this->redirect('/login');
        }

        $pending = [
            'state'      => Oidc::randomToken(),
            'nonce'      => Oidc::randomToken(),
            'verifier'   => Oidc::randomToken(), // 32 bytes -> 43 base64url chars (RFC 7636)
            'created_at' => time(),
        ];
        Session::set('oidc_pending', $pending);
        $config = $hermes->config();

        $query = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $config['client_id'],
            'redirect_uri'          => $config['redirect_uri'],
            'scope'                 => $config['scopes'],
            'state'                 => $pending['state'],
            'nonce'                 => $pending['nonce'],
            'code_challenge'        => Oidc::pkceChallenge($pending['verifier']),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return Response::redirect($authorizationEndpoint . (str_contains($authorizationEndpoint, '?') ? '&' : '?') . $query, 303);
    }

    /**
     * GET /auth/hermes/callback — canjea el código, valida el ID token, aprovisiona y abre sesión.
     */
    public function hermesCallback(Request $request): Response
    {
        $pending = Session::get('oidc_pending');
        Session::remove('oidc_pending'); // single use, whatever the outcome
        $state = (string) $request->query('state', '');

        if (!is_array($pending) && Auth::check()) {
            return $this->redirect('/admin'); // a replayed or re-opened callback of a finished login
        }

        if (
            !is_array($pending)
            || $state === ''
            || !hash_equals((string) ($pending['state'] ?? ''), $state)
            || (int) ($pending['created_at'] ?? 0) + self::PENDING_LOGIN_TTL_SECONDS < time()
        ) {
            return $this->hermesFailed($request, 'invalid_state', self::LOGIN_FAILED);
        }

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return $this->hermesFailed($request, 'authorization_error', 'Hermes no autorizó el inicio de sesión.', ['error' => substr($error, 0, 64)]);
        }

        $hermes = $this->hermes();
        $config = $hermes->config();
        try {
            $tokens  = $hermes->exchangeCode((string) $request->query('code', ''), (string) $pending['verifier']);
            $idToken = is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : '';
            $jwks    = $hermes->jwksFor($idToken);
        } catch (Throwable $exception) {
            // A 4xx from the token endpoint is Hermes refusing the code; anything else is Hermes being unreachable.
            $status   = (int) $exception->getCode();
            $rejected = $status >= 400 && $status < 500;
            return $this->hermesFailed(
                $request,
                $rejected ? 'token_rejected' : 'hermes_unavailable',
                $rejected ? self::LOGIN_FAILED : self::HERMES_UNAVAILABLE,
                ['detail' => substr($exception->getMessage(), 0, 120)]
            );
        }

        try {
            $claims = Oidc::validateIdToken($idToken, $jwks, $config['issuer'], $config['client_id'], (string) $pending['nonce'], time());
        } catch (Throwable $exception) {
            return $this->hermesFailed($request, 'token_rejected', self::LOGIN_FAILED, ['detail' => substr($exception->getMessage(), 0, 120)]);
        }

        $role = Oidc::roleFromClaims($claims['roles'] ?? null);
        if ($role === null) {
            return $this->hermesFailed($request, 'no_role', 'Tu cuenta de Hermes no tiene un rol asignado en HexBadge. Pide acceso a un administrador de Hermes.', ['sub' => $claims['sub']]);
        }

        $user = $this->provision($request, $claims, $role);
        if ($user instanceof Response) {
            return $user;
        }

        Auth::login($user, $request->ip(), ['sub' => (string) $claims['sub'], 'sid' => (string) $claims['sid']]);
        Logger::audit('user.login', (int) $user['id'], 'user', (string) $user['uuid'], ['method' => 'hermes', 'role' => (string) $user['role']], null, $request->ip(), $request->userAgent());

        return $this->redirect('/admin');
    }

    /**
     * POST /auth/hermes/backchannel-logout — lo llama Hermes, de servidor a servidor, sin
     * cookies ni CSRF: la prueba es el logout_token firmado.
     */
    public function backchannelLogout(Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8'];
        $hermes  = $this->hermes();
        $config  = $hermes->config();
        $raw     = (string) $request->input('logout_token', '');

        // Logged lines carry the reason class only: never the token.
        if (!$hermes->isConfigured() || $raw === '') {
            Logger::app('warning', 'Hermes back-channel logout: 400, ' . ($raw === '' ? 'missing logout_token' : 'OIDC client not configured'));
            return new Response('invalid_request', 400, $headers);
        }

        try {
            // A known kid is always served from cache, even stale: only a missing JWKS gets here.
            $jwks = $hermes->jwksFor($raw);
        } catch (Throwable $exception) {
            Logger::app('warning', 'Hermes back-channel logout: 503, JWKS unavailable (' . $exception::class . ')');
            return new Response('temporarily_unavailable', 503, $headers); // 5xx: Hermes retries once
        }

        try {
            $claims = Oidc::validateLogoutToken($raw, $jwks, $config['issuer'], $config['client_id'], time());
        } catch (Throwable $exception) {
            Logger::app('warning', 'Hermes back-channel logout: 400, token rejected (' . $exception::class . ')');
            return new Response('invalid_request', 400, $headers);
        }

        $sid = is_string($claims['sid'] ?? null) && $claims['sid'] !== '' ? $claims['sid'] : null;
        $sub = is_string($claims['sub'] ?? null) && $claims['sub'] !== '' ? $claims['sub'] : null;
        $db  = Database::getInstance();

        // One row is both the replay guard (jti primary key) and the revocation that Auth::check()
        // looks up, so recording the jti and ending the sessions is a single atomic insert.
        try {
            $db->insert('oidc_backchannel_logouts', [
                'jti'        => (string) $claims['jti'],
                'sid'        => $sid,
                'sub'        => $sub,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
                return new Response('', 200, $headers); // already processed: Hermes' retry of the same token
            }
            Logger::app('error', 'Hermes back-channel logout: 500, DB failure (' . $exception::class . ')');
            return new Response('server_error', 500, $headers);
        }

        // After recording: a cleanup failure must never block a revocation.
        try {
            $db->query('DELETE FROM oidc_backchannel_logouts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - self::BACKCHANNEL_RETENTION_SECONDS)]);
        } catch (Throwable $exception) {
            Logger::app('warning', 'Hermes back-channel logout: purge failed (' . $exception::class . ')');
        }

        Logger::audit('user.logout.backchannel', null, null, null, ['sid' => $sid, 'sub' => $sub], null, $request->ip(), $request->userAgent());

        return new Response('', 200, $headers);
    }

    /**
     * GET /awaiting-company — issuer/admin creado desde Hermes sin empresa asignada todavía.
     * Vuelve a leer sus empresas: apenas un superadmin le asigna una, entra al panel.
     */
    public function awaitingCompany(Request $request): Response
    {
        if (!Auth::check()) {
            return $this->redirect('/login');
        }
        if (!Auth::awaitingCompany()) {
            return $this->redirect('/admin');
        }

        $ids = User::companyIds((int) Auth::id());
        if ($ids !== []) {
            Session::set('company_ids', $ids);
            Session::set('company_id', $ids[0]);
            return $this->redirect('/admin');
        }

        return $this->view('auth/awaiting_company', [
            'pageTitle'   => 'Esperando asignación de empresa',
            'currentUser' => null, // auth layout: the panel navigation would only bounce back here
            'userName'    => (string) Session::get('user_name', ''),
        ]);
    }

    /**
     * Finds, links or creates the local user for a validated ID token and applies its claims
     * (name, verified email, role) in one transaction. Returns the user row, or the redirect
     * (already audited) when the login is refused.
     *
     * @param array<string,mixed> $claims
     * @return array<string,mixed>|Response
     */
    private function provision(Request $request, array $claims, string $role): array|Response
    {
        $sub      = (string) $claims['sub'];
        $email    = strtolower(trim(is_string($claims['email'] ?? null) ? $claims['email'] : ''));
        $verified = ($claims['email_verified'] ?? false) === true;
        $name     = trim(is_string($claims['name'] ?? null) ? $claims['name'] : '');
        $name     = mb_substr($name !== '' ? $name : $email, 0, 100);

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->hermesFailed($request, 'missing_email', self::LOGIN_FAILED, ['sub' => $sub]);
        }

        $db = Database::getInstance();
        // Two attempts: a duplicate key or deadlock on the first one means a concurrent first
        // login of this same person won the race, and the second attempt finds its row by sub.
        for ($attempt = 1; ; $attempt++) {
            $db->beginTransaction();
            try {
                [$user, $events] = $this->findOrCreateUser($db, $sub, $email, $verified, $name, $role);

                if ((int) $user['is_active'] !== 1) {
                    $db->rollBack();
                    return $this->hermesFailed($request, 'user_inactive', 'Tu cuenta está desactivada en HexBadge.', ['sub' => $sub], (int) $user['id']);
                }

                $events = array_merge($events, $this->applyClaims($db, $user, $name, $verified ? $email : null, $role));
                $db->commit();
                break;
            } catch (PDOException $exception) {
                $db->rollBack();
                $key = User::duplicateKey($exception->errorInfo);
                if ($attempt === 1 && self::isRetryable($exception, $key)) {
                    continue;
                }
                if ($key === 'email') {
                    // The email belongs to another local user (linked to another sub or inactive):
                    // Hermes may reuse an email, never a sub, so the accounts are not merged.
                    return $this->hermesFailed($request, 'email_conflict', self::EMAIL_CONFLICT, ['email' => $email, 'sub' => $sub]);
                }
                throw $exception;
            }
        }

        $row = User::find((int) $user['id']);
        if ($row === null) {
            return $this->hermesFailed($request, 'user_missing', self::LOGIN_FAILED, ['sub' => $sub]);
        }
        foreach ($events as [$action, $details]) {
            Logger::audit($action, (int) $row['id'], 'user', (string) $row['uuid'], ['sub' => $sub] + $details, null, $request->ip(), $request->userAgent());
        }
        return $row;
    }

    /**
     * The user behind $sub, locked for this transaction: matched by sub, else linked by
     * verified email, else created (JIT, no company yet). Returns the row and audit events.
     *
     * @return array{0: array<string,mixed>, 1: list<array{0: string, 1: array<string,mixed>}>}
     */
    private function findOrCreateUser(Database $db, string $sub, string $email, bool $verified, string $name, string $role): array
    {
        $user = $db->fetchOne('SELECT * FROM users WHERE hermes_sub = ? FOR UPDATE', [$sub]);
        if ($user !== null) {
            return [$user, []];
        }

        if ($verified) {
            // First Hermes login of an existing admin: link by verified email, only an active
            // account never linked before. A row linked to another sub is never relinked.
            $candidate = $db->fetchOne('SELECT * FROM users WHERE email = ? FOR UPDATE', [$email]);
            if ($candidate !== null && $candidate['hermes_sub'] === null && (int) $candidate['is_active'] === 1) {
                $db->update('users', ['hermes_sub' => $sub], 'id = ? AND hermes_sub IS NULL', [(int) $candidate['id']]);
                return [$candidate, [['user.hermes.linked', ['email' => $email, 'role' => $role]]]];
            }
            // Any other candidate falls through to the insert and hits the email key.
        }

        $id = $db->insert('users', [
            'uuid'          => uuid4(),
            'name'          => $name,
            'email'         => $email,
            'password_hash' => null,
            'role'          => $role,
            'company_id'    => null,
            'is_active'     => 1,
            'hermes_sub'    => $sub,
        ]);
        return [['id' => $id, 'is_active' => 1, 'role' => $role], [['user.hermes.provisioned', ['email' => $email, 'role' => $role]]]];
    }

    /**
     * Writes the token's name, role and (only when verified) email. The role is read from
     * Hermes only at login (Hermes/Fides convention): a change in Hermes applies on the next login.
     * Returns the audit events of a role change.
     *
     * @param array<string,mixed> $user
     * @return list<array{0: string, 1: array<string,mixed>}>
     */
    private function applyClaims(Database $db, array $user, string $name, ?string $verifiedEmail, string $role): array
    {
        $fields  = ['name' => $name, 'role' => $role];
        $events  = [];
        $oldRole = (string) $user['role'];

        if ($oldRole === 'superadmin' && $role !== 'superadmin' && $this->isLastActiveSuperadmin($db, (int) $user['id'])) {
            // Never lock everyone out of the panel because of a Hermes role change.
            $fields['role'] = 'superadmin';
            $events[] = ['user.hermes.role_demotion_blocked', ['claimed_role' => $role]];
        } elseif ($oldRole !== $role) {
            $events[] = ['user.role.changed', ['from' => $oldRole, 'to' => $role, 'method' => 'hermes']];
        }

        if ($verifiedEmail !== null) {
            $fields['email'] = $verifiedEmail;
        }
        $db->update('users', $fields, 'id = ?', [(int) $user['id']]);
        return $events;
    }

    /**
     * Locks the active superadmin rows, so two concurrent demotions cannot both pass.
     * ponytail: role is not indexed, so this locks the scanned users rows; fine for a small table.
     */
    private function isLastActiveSuperadmin(Database $db, int $userId): bool
    {
        $ids = array_map('intval', array_column(
            $db->fetchAll("SELECT id FROM users WHERE role = 'superadmin' AND is_active = 1 FOR UPDATE"),
            'id'
        ));
        return array_diff($ids, [$userId]) === [];
    }

    /** A duplicate key (concurrent first login) or a deadlock: worth one more attempt. */
    private static function isRetryable(PDOException $exception, ?string $duplicateKey): bool
    {
        return $duplicateKey !== null || $exception->getCode() === self::SQLSTATE_SERIALIZATION_FAILURE;
    }

    /**
     * @param array<string,mixed> $details
     */
    private function hermesFailed(Request $request, string $reason, string $message, array $details = [], ?int $userId = null): Response
    {
        Logger::audit('user.login.failed', $userId, null, null, ['method' => 'hermes', 'reason' => $reason] + $details, null, $request->ip(), $request->userAgent());
        Session::flash('error', $message);
        return $this->redirect('/login');
    }

    private function loginView(string $error, string $oldEmail, int $status = 401): Response
    {
        return $this->view('auth/login', [
            'pageTitle'  => 'Ingresar',
            'error'      => $error,
            'oldEmail'   => $oldEmail,
            'reset'      => false,
            'localLogin' => Auth::localLoginEnabled(),
            'configured' => $this->hermes()->isConfigured(),
        ], $status);
    }
}
