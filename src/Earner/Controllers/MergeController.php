<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Totp;
use HexBadge\Core\Validator;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\Earner;
use HexBadge\Services\WalletMergeService;

/**
 * Fusión de wallets del receptor: vincular un segundo correo (con verificación
 * por email) y unir ambas cuentas en una sola.
 */
final class MergeController extends EarnerBaseController
{
    /**
     * POST /me/emails/link — el earner logueado pide vincular otro correo.
     * Envía la verificación a ese correo (respuesta genérica anti-enumeración).
     */
    public function startLink(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        if (!(new RateLimiter())->check($request->ip(), 'merge_link', 5, 3600)) {
            Session::flash('error', 'Demasiados intentos. Esperá unos minutos.');
            return Response::redirect('/me/profile');
        }

        try {
            $email = (new Validator())->email((string) $request->input('email', ''));
            (new WalletMergeService())->startLink((int) $earner['id'], $email);
            Session::flash('success', 'Te enviamos un enlace de verificación a ese correo. Abrilo para unir las acreditaciones.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        return Response::redirect('/me/profile');
    }

    /**
     * GET /me/merge/{token} — abierto desde el correo a vincular: muestra el
     * comparativo de perfiles y la elección de qué conservar.
     */
    public function showConfirm(Request $request, string $token): Response
    {
        $merge = (new WalletMergeService())->findByVerifyToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
        }
        return $this->renderConfirm($token, $merge);
    }

    /**
     * POST /me/merge/{token} — aplica la fusión con las elecciones de perfil.
     * Antes exige probar la titularidad de la cuenta origen (contraseña + 2FA si
     * los tiene): poseer el correo no basta para reclamar sus acreditaciones.
     */
    public function apply(Request $request, string $token): Response
    {
        CSRF::check($request);
        $svc   = new WalletMergeService();
        $merge = $svc->findByVerifyToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
        }

        // Autenticación de la cuenta ORIGEN (la que aporta las acreditaciones).
        $source = Earner::findByEmail((string) $merge['source_email']);
        if ($source !== null && Earner::hasAccount($source)) {
            if (!(new RateLimiter())->check($request->ip(), 'merge_apply', 10, 900)) {
                return $this->renderConfirm($token, $merge, 'Demasiados intentos. Esperá unos minutos.', 429);
            }
            if (!password_verify((string) $request->input('password', ''), (string) $source['password_hash'])) {
                return $this->renderConfirm($token, $merge, 'La contraseña de esa cuenta es incorrecta.', 422);
            }
            if ((int) ($source['totp_enabled'] ?? 0) === 1
                && !Totp::verify((string) $source['totp_secret'], (string) $request->input('totp', ''))) {
                return $this->renderConfirm($token, $merge, 'El código de verificación (2FA) es incorrecto.', 422);
            }
        }

        // Elecciones de perfil: por defecto se conserva el destino; el nombre se
        // agrupa (first+last) en un solo control.
        $useName = $request->input('use_name') === '1';
        $choices = ['first_name' => $useName ? 'source' : 'target', 'last_name' => $useName ? 'source' : 'target'];
        foreach (['profile_bio', 'profile_url', 'avatar_filename', 'cover_filename', 'linkedin_url', 'instagram_url', 'x_url', 'github_url'] as $f) {
            $choices[$f] = $request->input('use_' . $f) === '1' ? 'source' : 'target';
        }

        $result = $svc->applyMerge($merge, $choices);
        Session::flash('success', $result['moved'] > 0
            ? '¡Listo! Unimos ' . $result['moved'] . ' acreditación(es) en tu wallet.'
            : '¡Listo! El correo quedó vinculado a tu wallet.');

        return Response::redirect('/earner/' . $result['target_uuid']);
    }

    /**
     * Renderiza la pantalla de confirmación de la fusión (comparativo de perfil
     * + autenticación de la cuenta origen si la tiene).
     *
     * @param array<string,mixed> $merge
     */
    private function renderConfirm(string $token, array $merge, ?string $error = null, int $status = 200): Response
    {
        $target = Earner::find((int) $merge['target_earner_id']);
        $source = Earner::findByEmail((string) $merge['source_email']);

        return $this->view('merge/confirm', [
            'pageTitle'     => 'Unir mis acreditaciones',
            'token'         => $token,
            'merge'         => $merge,
            'target'        => $target,
            'source'        => $source,          // puede ser null (correo sin cuenta)
            'needsPassword' => $source !== null && Earner::hasAccount($source),
            'needs2fa'      => $source !== null && (int) ($source['totp_enabled'] ?? 0) === 1,
            'error'         => $error,
        ], $status);
    }

    /**
     * GET /me/merge/revert/{token} — desde el aviso por correo: confirma deshacer.
     */
    public function showRevert(Request $request, string $token): Response
    {
        $merge = (new WalletMergeService())->findByRevertToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
        }
        return $this->view('merge/revert', [
            'pageTitle' => 'Deshacer la unión',
            'token'     => $token,
            'merge'     => $merge,
        ]);
    }

    /**
     * POST /me/merge/revert/{token} — deshace la fusión.
     */
    public function revert(Request $request, string $token): Response
    {
        CSRF::check($request);
        $svc   = new WalletMergeService();
        $merge = $svc->findByRevertToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
        }
        $svc->revertMerge($merge);
        Session::flash('success', 'Deshicimos la unión. Cada correo vuelve a tener su propia wallet.');
        return Response::redirect('/login');
    }
}
