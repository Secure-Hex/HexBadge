<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Totp;
use HexBadge\Core\Validator;
use HexBadge\Models\User;
use InvalidArgumentException;

/**
 * Cuenta del administrador: cambio de contraseña y 2FA (TOTP).
 */
final class AccountController extends Controller
{
    private const PENDING_SECRET = 'totp_pending_secret';

    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $user = Auth::user();
        return $this->view('account/index', [
            'pageTitle'   => 'Mi cuenta',
            'user'        => $user,
            'totpEnabled' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'errors'      => [],
        ]);
    }

    public function changePassword(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $user = Auth::user();
        $v    = new Validator();
        try {
            $current = (string) $request->input('current_password', '');
            if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
                throw new InvalidArgumentException('La contraseña actual no es correcta.');
            }
            $new = $v->password((string) $request->input('new_password', ''));
            if (!hash_equals($new, (string) $request->input('new_password_confirm', ''))) {
                throw new InvalidArgumentException('Las contraseñas nuevas no coinciden.');
            }
            User::updateById((int) $user['id'], [
                'password_hash' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
            Logger::audit('user.password.changed', (int) $user['id'], 'user', (string) $user['uuid']);
            \HexBadge\Services\PasswordResetService::notifyChanged('admin', $user, $request->ip());
            Session::flash('success', 'Contraseña actualizada.');
            return $this->redirect('/admin/account');
        } catch (InvalidArgumentException $e) {
            return $this->accountError($user, $e->getMessage());
        }
    }

    /**
     * GET /admin/account/totp — inicia el enrolamiento (genera secreto).
     */
    public function totpSetup(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $user = Auth::user();
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            return $this->redirect('/admin/account');
        }

        $secret = Totp::generateSecret();
        Session::set(self::PENDING_SECRET, $secret);

        $issuer  = (string) config('app.name', 'HexBadge');
        $uri     = Totp::provisioningUri($secret, (string) $user['email'], $issuer);
        return $this->view('account/totp_setup', [
            'pageTitle' => 'Activar 2FA',
            'secret'    => $secret,
            'uri'       => $uri,
            'qrSvg'     => \HexBadge\Core\QrCode::svg($uri),
            'errors'    => [],
        ]);
    }

    /**
     * POST /admin/account/totp — confirma y activa el 2FA.
     */
    public function totpEnable(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $user   = Auth::user();
        $secret = (string) Session::get(self::PENDING_SECRET, '');
        $code   = (string) $request->input('code', '');

        if ($secret === '' || !Totp::verify($secret, $code)) {
            $issuer = (string) config('app.name', 'HexBadge');
            $uri    = Totp::provisioningUri($secret, (string) $user['email'], $issuer);
            return $this->view('account/totp_setup', [
                'pageTitle' => 'Activar 2FA',
                'secret'    => $secret,
                'uri'       => $uri,
                'qrSvg'     => \HexBadge\Core\QrCode::svg($uri),
                'errors'    => ['Código incorrecto. Probá de nuevo con el código actual de tu app.'],
            ], 422);
        }

        User::updateById((int) $user['id'], ['totp_secret' => $secret, 'totp_enabled' => 1]);
        Session::remove(self::PENDING_SECRET);
        Logger::audit('user.totp.enabled', (int) $user['id'], 'user', (string) $user['uuid']);
        Session::flash('success', '2FA activado. La próxima vez que ingreses te pedirá el código.');
        return $this->redirect('/admin/account');
    }

    /**
     * POST /admin/account/totp/disable — desactiva el 2FA (requiere contraseña).
     */
    public function totpDisable(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $user = Auth::user();
        if ($user === null || !password_verify((string) $request->input('password', ''), (string) $user['password_hash'])) {
            return $this->accountError($user, 'Contraseña incorrecta; no se desactivó el 2FA.');
        }

        User::updateById((int) $user['id'], ['totp_secret' => null, 'totp_enabled' => 0]);
        Logger::audit('user.totp.disabled', (int) $user['id'], 'user', (string) $user['uuid']);
        Session::flash('success', '2FA desactivado.');
        return $this->redirect('/admin/account');
    }

    /**
     * @param array<string,mixed>|null $user
     */
    private function accountError(?array $user, string $message): Response
    {
        return $this->view('account/index', [
            'pageTitle'   => 'Mi cuenta',
            'user'        => $user,
            'totpEnabled' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'errors'      => [$message],
        ], 422);
    }
}
