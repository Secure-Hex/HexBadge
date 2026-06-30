<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Totp;
use HexBadge\Core\Validator;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\Earner;
use InvalidArgumentException;

/**
 * Seguridad de la cuenta del receptor: contraseña y 2FA (TOTP).
 */
final class SecurityController extends EarnerBaseController
{
    private const PENDING_SECRET = 'earner_totp_pending';

    public function index(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        return $this->view('security', [
            'pageTitle'   => 'Seguridad',
            'earner'      => $earner,
            'totpEnabled' => (int) ($earner['totp_enabled'] ?? 0) === 1,
            'errors'      => [],
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $v = new Validator();
        try {
            if (!password_verify((string) $request->input('current_password', ''), (string) $earner['password_hash'])) {
                throw new InvalidArgumentException('La contraseña actual no es correcta.');
            }
            $new = $v->password((string) $request->input('new_password', ''));
            if (!hash_equals($new, (string) $request->input('new_password_confirm', ''))) {
                throw new InvalidArgumentException('Las contraseñas nuevas no coinciden.');
            }
            Earner::updateById((int) $earner['id'], ['password_hash' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12])]);
            Logger::audit('earner.password.changed', null, 'earner', (string) $earner['uuid']);
            \HexBadge\Services\PasswordResetService::notifyChanged('earner', $earner, $request->ip());
            Session::flash('success', 'Contraseña actualizada.');
            return Response::redirect('/me/security');
        } catch (InvalidArgumentException $e) {
            return $this->error($earner, $e->getMessage());
        }
    }

    public function totpSetup(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        if ((int) ($earner['totp_enabled'] ?? 0) === 1) {
            return Response::redirect('/me/security');
        }
        $secret = Totp::generateSecret();
        Session::set(self::PENDING_SECRET, $secret);
        $uri = Totp::provisioningUri($secret, (string) $earner['email'], (string) config('app.name', 'HexBadge'));
        return $this->view('totp_setup', [
            'pageTitle' => 'Activar 2FA',
            'secret'    => $secret,
            'uri'       => $uri,
            'qrSvg'     => \HexBadge\Core\QrCode::svg($uri),
            'errors'    => [],
        ]);
    }

    public function totpEnable(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $secret = (string) Session::get(self::PENDING_SECRET, '');
        if ($secret === '' || !Totp::verify($secret, (string) $request->input('code', ''))) {
            $uri = Totp::provisioningUri($secret, (string) $earner['email'], (string) config('app.name', 'HexBadge'));
            return $this->view('totp_setup', [
                'pageTitle' => 'Activar 2FA',
                'secret'    => $secret,
                'uri'       => $uri,
                'qrSvg'     => \HexBadge\Core\QrCode::svg($uri),
                'errors'    => ['Código incorrecto. Probá con el código actual de tu app.'],
            ], 422);
        }
        Earner::updateById((int) $earner['id'], ['totp_secret' => $secret, 'totp_enabled' => 1]);
        Session::remove(self::PENDING_SECRET);
        Logger::audit('earner.totp.enabled', null, 'earner', (string) $earner['uuid']);
        Session::flash('success', '2FA activado.');
        return Response::redirect('/me/security');
    }

    public function totpDisable(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        if (!password_verify((string) $request->input('password', ''), (string) $earner['password_hash'])) {
            return $this->error($earner, 'Contraseña incorrecta; no se desactivó el 2FA.');
        }
        Earner::updateById((int) $earner['id'], ['totp_secret' => null, 'totp_enabled' => 0]);
        Logger::audit('earner.totp.disabled', null, 'earner', (string) $earner['uuid']);
        Session::flash('success', '2FA desactivado.');
        return Response::redirect('/me/security');
    }

    /**
     * @param array<string,mixed> $earner
     */
    private function error(array $earner, string $message): Response
    {
        return $this->view('security', [
            'pageTitle'   => 'Seguridad',
            'earner'      => $earner,
            'totpEnabled' => (int) ($earner['totp_enabled'] ?? 0) === 1,
            'errors'      => [$message],
        ], 422);
    }
}
