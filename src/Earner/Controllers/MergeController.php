<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
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
        $svc   = new WalletMergeService();
        $merge = $svc->findByVerifyToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
        }

        $target = Earner::find((int) $merge['target_earner_id']);
        $source = Earner::findByEmail((string) $merge['source_email']);

        return $this->view('merge/confirm', [
            'pageTitle' => 'Unir mis acreditaciones',
            'token'     => $token,
            'merge'     => $merge,
            'target'    => $target,
            'source'    => $source,          // puede ser null (correo sin cuenta)
            'fields'    => $this->fieldLabels(),
        ]);
    }

    /**
     * POST /me/merge/{token} — aplica la fusión con las elecciones de perfil.
     */
    public function apply(Request $request, string $token): Response
    {
        CSRF::check($request);
        $svc   = new WalletMergeService();
        $merge = $svc->findByVerifyToken($token);
        if ($merge === null) {
            return $this->view('merge/invalid', ['pageTitle' => 'Enlace no válido'], 410);
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
     * Etiquetas legibles de los campos de perfil elegibles en la fusión.
     *
     * @return array<string,string>
     */
    private function fieldLabels(): array
    {
        return [
            'name'            => 'Nombre',
            'profile_bio'     => 'Bio',
            'profile_url'     => 'Sitio web',
            'avatar_filename' => 'Foto de perfil',
            'cover_filename'  => 'Foto de portada',
            'linkedin_url'    => 'LinkedIn',
            'instagram_url'   => 'Instagram',
            'x_url'           => 'X',
            'github_url'      => 'GitHub',
        ];
    }
}
