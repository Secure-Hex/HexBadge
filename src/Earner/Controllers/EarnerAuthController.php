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
        return $this->view('login', ['pageTitle' => 'Ingresar', 'error' => null, 'oldEmail' => '']);
    }

    public function login(Request $request): Response
    {
        CSRF::check($request);

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
