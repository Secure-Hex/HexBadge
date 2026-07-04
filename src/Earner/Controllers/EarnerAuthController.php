<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Totp;
use HexBadge\Core\Validator;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\Earner;
use HexBadge\Services\PasswordResetService;

/**
 * Login / logout de receptores en el portal earner.
 */
final class EarnerAuthController extends EarnerBaseController
{
    public function showLogin(Request $request): Response
    {
        if (EarnerAuth::check()) {
            return Response::redirect('/me');
        }
        return $this->view('login', [
            'pageTitle' => 'Ingresar',
            'error'     => null,
            'oldEmail'  => '',
            'reset'     => $request->query('reset') === '1',
        ]);
    }

    /**
     * GET /forgot-password — pedir enlace de recuperación.
     */
    public function showForgot(Request $request): Response
    {
        if (EarnerAuth::check()) {
            return Response::redirect('/me');
        }
        return $this->view('forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => false, 'error' => null]);
    }

    /**
     * POST /forgot-password — envía el enlace (respuesta genérica).
     */
    public function sendReset(Request $request): Response
    {
        CSRF::check($request);

        $ip      = $request->ip();
        $limiter = new \HexBadge\Core\RateLimiter();
        if (!$limiter->check($ip, 'pwreset', 5, 900)) {
            return $this->view('forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.'], 429);
        }

        try {
            $email = (new Validator())->email((string) $request->input('email', ''));
            (new PasswordResetService())->request('earner', $email);
        } catch (\InvalidArgumentException) {
            // Anti-enumeración: mensaje genérico igual.
        }
        return $this->view('forgot_password', ['pageTitle' => 'Recuperar contraseña', 'sent' => true, 'error' => null]);
    }

    /**
     * GET /reset-password/{token} — formulario de nueva contraseña.
     */
    public function showReset(Request $request, string $token): Response
    {
        $valid = (new PasswordResetService())->findValid('earner', $token) !== null;
        return $this->view('reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => $valid, 'errors' => []]);
    }

    /**
     * POST /reset-password/{token} — aplica la nueva contraseña.
     */
    public function reset(Request $request, string $token): Response
    {
        CSRF::check($request);

        $svc = new PasswordResetService();
        if ($svc->findValid('earner', $token) === null) {
            return $this->view('reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => false, 'errors' => []], 410);
        }
        try {
            $pw = (new Validator())->password((string) $request->input('password', ''));
            if (!hash_equals($pw, (string) $request->input('password_confirm', ''))) {
                throw new \InvalidArgumentException('Las contraseñas no coinciden.');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->view('reset_password', ['pageTitle' => 'Nueva contraseña', 'token' => $token, 'valid' => true, 'errors' => [$e->getMessage()]], 422);
        }
        $svc->reset('earner', $token, $pw, $request->ip());
        return Response::redirect('/login?reset=1');
    }

    public function login(Request $request): Response
    {
        // ponytail: login sin CSRF a pedido; el resto de POST sigue protegido.
        $email = (string) $request->input('email', '');
        try {
            $email = (new Validator())->email($email);
        } catch (\InvalidArgumentException) {
            return $this->loginError('Credenciales inválidas', $email);
        }

        $earner = EarnerAuth::attempt($email, (string) $request->input('password', ''));
        if ($earner === null) {
            return $this->loginError('Credenciales inválidas', $email);
        }

        // 2FA: si está activo, pedimos el código antes de abrir sesión.
        if ((int) ($earner['totp_enabled'] ?? 0) === 1) {
            Session::set('earner_pending_2fa', (int) $earner['id']);
            return Response::redirect('/login/2fa');
        }

        EarnerAuth::login($earner);
        return Response::redirect('/me');
    }

    public function showTwoFactor(Request $request): Response
    {
        if (!Session::has('earner_pending_2fa')) {
            return Response::redirect('/login');
        }
        return $this->view('two_factor', ['pageTitle' => 'Verificación 2FA', 'error' => null]);
    }

    public function twoFactor(Request $request): Response
    {
        CSRF::check($request);

        $id = Session::get('earner_pending_2fa');
        if (!is_int($id)) {
            return Response::redirect('/login');
        }
        $earner = Earner::find($id);
        if ($earner === null || (int) $earner['totp_enabled'] !== 1) {
            Session::remove('earner_pending_2fa');
            return Response::redirect('/login');
        }
        if (!Totp::verify((string) $earner['totp_secret'], (string) $request->input('code', ''))) {
            return $this->view('two_factor', ['pageTitle' => 'Verificación 2FA', 'error' => 'Código incorrecto.'], 401);
        }
        Session::remove('earner_pending_2fa');
        EarnerAuth::login($earner);
        return Response::redirect('/me');
    }

    public function logout(Request $request): Response
    {
        EarnerAuth::logout();
        return Response::redirect('/');
    }

    private function loginError(string $msg, string $oldEmail): Response
    {
        return $this->view('login', ['pageTitle' => 'Ingresar', 'error' => $msg, 'oldEmail' => $oldEmail], 401);
    }
}
