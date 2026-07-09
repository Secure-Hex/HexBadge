<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\ImageService;

/**
 * Panel privado del receptor: sus badges y edición de perfil.
 */
final class ProfileController extends EarnerBaseController
{
    /**
     * GET /me — wallet privada del receptor logueado.
     */
    public function me(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        return Response::redirect('/earner/' . (string) $earner['uuid']);
    }

    /**
     * GET /me/profile — formulario de perfil.
     */
    public function editProfile(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        return $this->view('profile', [
            'pageTitle' => 'Mi perfil',
            'earner'    => $earner,
            'errors'    => [],
        ]);
    }

    /**
     * POST /me/profile — guarda cambios de perfil.
     */
    public function saveProfile(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $v = new Validator();
        try {
            // Solo textos: las fotos se suben/eliminan al instante en sus
            // propios endpoints (uploadPhoto/removePhoto).
            $updates = [
                'first_name'    => $v->name((string) $request->input('first_name', '')),
                'last_name'     => $v->name((string) $request->input('last_name', '')),
                'profile_bio'   => $v->text((string) $request->input('profile_bio', ''), 1000, false),
                'profile_url'   => $v->url((string) $request->input('profile_url', ''), false),
                'linkedin_url'  => $v->url((string) $request->input('linkedin_url', ''), false),
                'instagram_url' => $v->url((string) $request->input('instagram_url', ''), false),
                'x_url'         => $v->url((string) $request->input('x_url', ''), false),
                'github_url'    => $v->url((string) $request->input('github_url', ''), false),
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->view('profile', ['pageTitle' => 'Mi perfil', 'earner' => array_merge($earner, $request->all()), 'errors' => [$e->getMessage()]], 422);
        }

        Earner::updateById((int) $earner['id'], $updates);
        Session::flash('success', 'Perfil actualizado.');
        return Response::redirect('/earner/' . (string) $earner['uuid']);
    }

    /**
     * POST /me/profile/photo/delete — elimina de inmediato la foto de perfil o
     * portada (field=avatar|cover), sin pasar por el resto del formulario.
     */
    public function removePhoto(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $field  = (string) $request->input('field', '');
        $column = ['avatar' => 'avatar_filename', 'cover' => 'cover_filename'][$field] ?? null;
        if ($column === null) {
            return Response::redirect('/me/profile');
        }

        $old = (string) ($earner[$column] ?? '');
        if ($old !== '') {
            (new ImageService())->deleteProfile($old);
            Earner::updateById((int) $earner['id'], [$column => null]);
            Session::flash('success', $field === 'avatar' ? 'Foto de perfil eliminada.' : 'Foto de portada eliminada.');
        }
        return Response::redirect('/me/profile');
    }

    /**
     * POST /me/profile/photo/upload — sube y guarda de inmediato la foto de
     * perfil o portada (field=avatar|cover), reemplazando la anterior.
     */
    public function uploadPhoto(Request $request): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $field  = (string) $request->input('field', '');
        $column = ['avatar' => 'avatar_filename', 'cover' => 'cover_filename'][$field] ?? null;
        if ($column === null) {
            return Response::redirect('/me/profile');
        }

        $file = $request->file($field);
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::redirect('/me/profile');
        }

        $img = new ImageService();
        try {
            $new = $img->processProfileImage($file);
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect('/me/profile');
        }

        $old = (string) ($earner[$column] ?? '');
        Earner::updateById((int) $earner['id'], [$column => $new]);
        if ($old !== '') {
            $img->deleteProfile($old);
        }
        Session::flash('success', $field === 'avatar' ? 'Foto de perfil actualizada.' : 'Foto de portada actualizada.');
        return Response::redirect('/me/profile');
    }

    /**
     * GET /me/badge/{uuid} — revisar un badge pendiente y decidir.
     */
    public function showBadge(Request $request, string $uuid): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        $badge = $this->ownedBadge($uuid, (int) $earner['id']);
        if ($badge === null) {
            return $this->view('badge_not_found', ['pageTitle' => 'Badge no encontrado'], 404);
        }
        if ($badge['status'] === 'accepted') {
            return Response::redirect('/earner/' . (string) $earner['uuid']);
        }
        if ($badge['status'] === 'revoked') {
            return $this->view('accept_invalid', ['pageTitle' => 'Badge revocado', 'revoked' => true], 410);
        }

        return $this->view('badge_decide', [
            'pageTitle' => (string) $badge['template_name'],
            'badge'     => $badge,
            'tags'      => BadgeTemplate::decodeTags($badge['skills_tags'] ?? null),
            'verifyUrl' => public_url('verify/' . (string) $badge['uuid']),
        ]);
    }

    /**
     * POST /me/badge/{uuid}/accept — aceptar (dueño autenticado, sin token).
     */
    public function acceptBadge(Request $request, string $uuid): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $badge = $this->ownedBadge($uuid, (int) $earner['id']);
        if ($badge === null) {
            return $this->view('badge_not_found', ['pageTitle' => 'Badge no encontrado'], 404);
        }

        $updated = Database::getInstance()->query(
            "UPDATE issued_badges SET status = 'accepted', accepted_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [(int) $badge['id']]
        )->rowCount();

        if ($updated > 0) {
            Logger::audit('badge.accepted', null, 'issued_badge', $uuid, ['earner' => $earner['uuid'], 'via' => 'wallet']);
            Session::flash('success', '¡Badge aceptado! Ya forma parte de tu perfil.');
        }
        return Response::redirect('/earner/' . (string) $earner['uuid'] . ($updated > 0 ? '?accepted=1' : ''));
    }

    /**
     * POST /me/badge/{uuid}/reject — rechazar un badge pendiente.
     */
    public function rejectBadge(Request $request, string $uuid): Response
    {
        $earner = EarnerAuth::user();
        if ($earner === null) {
            return Response::redirect('/login');
        }
        CSRF::check($request);

        $badge = $this->ownedBadge($uuid, (int) $earner['id']);
        if ($badge === null) {
            return $this->view('badge_not_found', ['pageTitle' => 'Badge no encontrado'], 404);
        }

        $updated = Database::getInstance()->query(
            "UPDATE issued_badges SET status = 'rejected' WHERE id = ? AND status = 'pending'",
            [(int) $badge['id']]
        )->rowCount();

        if ($updated > 0) {
            Logger::audit('badge.rejected', null, 'issued_badge', $uuid, ['earner' => $earner['uuid']]);
            Session::flash('success', 'Badge rechazado.');
        }
        return Response::redirect('/earner/' . (string) $earner['uuid']);
    }

    /**
     * Devuelve el badge si pertenece a este earner; si no, null.
     *
     * @return array<string,mixed>|null
     */
    private function ownedBadge(string $uuid, int $earnerId): ?array
    {
        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null || (int) $badge['earner_id'] !== $earnerId) {
            return null;
        }
        return $badge;
    }
}
