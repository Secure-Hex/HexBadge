<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Services\PasswordResetService;

/**
 * Login / logout (CLAUDE.md §4.1, §6).
 */
final class AuthController extends Controller
{
    /**
     * GET /login — muestra el formulario (o redirige si ya hay sesión).
     */
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return $this->redirect('/admin');
        }
        return $this->view('auth/login', [
            'pageTitle' => 'Ingresar',
            'reset'     => $request->query('reset') === '1',
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
        $this->verifyCsrf($request);

        $ip      = $request->ip();
        $limiter = new RateLimiter();

        $maxAttempts = (int) config('rate_limit.login', 5);
        $window      = (int) config('rate_limit.login_window', 900);

        if (!$limiter->check($ip, 'login', $maxAttempts, $window)) {
            Logger::audit('user.login.rate_limited', null, null, null, [], null, $ip, $request->userAgent());
            return $this->view('auth/login', [
                'pageTitle' => 'Ingresar',
                'error'     => 'Demasiados intentos. Intenta nuevamente en unos minutos.',
            ], 429);
        }

        $emailRaw = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        try {
            $validator = new Validator();
            $email     = $validator->email($emailRaw);
        } catch (\InvalidArgumentException) {
            return $this->loginError('Credenciales inválidas', $emailRaw);
        }

        $user = Auth::attempt($email, $password);

        if ($user === null) {
            Logger::audit('user.login.failed', null, null, null, ['email' => $email], null, $ip, $request->userAgent());
            return $this->loginError('Credenciales inválidas', $emailRaw);
        }

        $limiter->clear($ip, 'login');

        // Si el usuario tiene 2FA activo, no abrimos sesión todavía: guardamos
        // el id pendiente y pedimos el código TOTP.
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            Session::set('pending_2fa_user_id', (int) $user['id']);
            return $this->redirect('/login/2fa');
        }

        Auth::login($user, $ip);
        Logger::audit('user.login', (int) $user['id'], 'user', (string) $user['uuid'], [], null, $ip, $request->userAgent());
        return $this->redirect('/admin');
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
        if ($user === null || (int) $user['totp_enabled'] !== 1) {
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
        Logger::audit('user.login', (int) $user['id'], 'user', (string) $user['uuid'], ['2fa' => true], null, $ip, $request->userAgent());
        return $this->redirect('/admin');
    }

    /**
     * GET /logout — cierra la sesión.
     */
    public function logout(Request $request): Response
    {
        $userId = Auth::id();
        if ($userId !== null) {
            Logger::audit('user.logout', $userId, null, null, [], null, $request->ip(), $request->userAgent());
        }
        Auth::logout();
        return $this->redirect('/login');
    }

    private function loginError(string $message, string $oldEmail): Response
    {
        return $this->view('auth/login', [
            'pageTitle' => 'Ingresar',
            'error'     => $message,
            'oldEmail'  => $oldEmail,
        ], 401);
    }
}
