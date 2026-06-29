<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Validator;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;
use InvalidArgumentException;

/**
 * Aceptación (claim) de badges por el receptor (CLAUDE.md §6.6 + cambios).
 *
 * Flujo: el receptor llega del email a /accept/{token}. Debe iniciar sesión
 * (si ya tiene cuenta) o registrarse (definir contraseña). Recién entonces
 * reclama el badge, que queda atado a SU cuenta y no puede ser reclamado
 * por otra persona.
 */
final class AcceptController extends EarnerBaseController
{
    /**
     * GET /accept/{token} — muestra el formulario de login o registro.
     */
    public function show(Request $request, string $token): Response
    {
        $badge = $this->resolveBadge($token);
        if ($badge instanceof Response) {
            return $badge;
        }

        // Ya reclamado: exclusividad. Solo el dueño ve su wallet; otros, aviso.
        if ($badge['status'] === 'accepted') {
            if (EarnerAuth::id() === (int) $badge['earner_id']) {
                return Response::redirect('/earner/' . (string) $badge['earner_uuid']);
            }
            return $this->view('accept_claimed', ['pageTitle' => 'Badge ya reclamado'], 409);
        }

        $earner = Earner::find((int) $badge['earner_id']);
        $mode   = ($earner !== null && Earner::hasAccount($earner)) ? 'login' : 'register';

        return $this->view('accept', [
            'pageTitle'    => 'Aceptar tu badge',
            'token'        => $token,
            'mode'         => $mode,
            'badge'        => $badge,
            'email'        => (string) $badge['earner_email'],
            'firstName'    => (string) $badge['first_name'],
            'lastName'     => (string) $badge['last_name'],
            'requiresTotp' => $mode === 'login' && (int) ($earner['totp_enabled'] ?? 0) === 1,
            'errors'       => [],
        ]);
    }

    /**
     * POST /accept/{token} — procesa login/registro y reclama el badge.
     */
    public function claim(Request $request, string $token): Response
    {
        CSRF::check($request);

        $badge = $this->resolveBadge($token);
        if ($badge instanceof Response) {
            return $badge;
        }
        if ($badge['status'] === 'accepted') {
            if (EarnerAuth::id() === (int) $badge['earner_id']) {
                return Response::redirect('/earner/' . (string) $badge['earner_uuid']);
            }
            return $this->view('accept_claimed', ['pageTitle' => 'Badge ya reclamado'], 409);
        }

        $earner = Earner::find((int) $badge['earner_id']);
        if ($earner === null) {
            return $this->view('accept_invalid', ['pageTitle' => 'Enlace inválido'], 404);
        }
        $mode = Earner::hasAccount($earner) ? 'login' : 'register';
        $v    = new Validator();

        try {
            if ($mode === 'register') {
                $password = $v->password((string) $request->input('password', ''));
                if (!hash_equals($password, (string) $request->input('password_confirm', ''))) {
                    throw new InvalidArgumentException('Las contraseñas no coinciden.');
                }
                Earner::setPassword((int) $earner['id'], $password);
                $earner = Earner::find((int) $earner['id']);
            } else {
                $authed = EarnerAuth::attempt((string) $earner['email'], (string) $request->input('password', ''));
                if ($authed === null) {
                    throw new InvalidArgumentException('Contraseña incorrecta.');
                }
                // Si tiene 2FA, exigir el código TOTP también para reclamar.
                if ((int) ($authed['totp_enabled'] ?? 0) === 1
                    && !\HexBadge\Core\Totp::verify((string) $authed['totp_secret'], (string) $request->input('code', ''))) {
                    throw new InvalidArgumentException('Código 2FA incorrecto.');
                }
                $earner = $authed;
            }
        } catch (InvalidArgumentException $e) {
            return $this->view('accept', [
                'pageTitle'    => 'Aceptar tu badge',
                'token'        => $token,
                'mode'         => $mode,
                'badge'        => $badge,
                'email'        => (string) $earner['email'],
                'firstName'    => (string) $badge['first_name'],
                'lastName'     => (string) $badge['last_name'],
                'requiresTotp' => $mode === 'login' && (int) ($earner['totp_enabled'] ?? 0) === 1,
                'errors'       => [$e->getMessage()],
            ], 422);
        }

        EarnerAuth::login($earner);

        // Exclusividad: la sesión autenticada debe ser el dueño del badge.
        if (EarnerAuth::id() !== (int) $badge['earner_id']) {
            return $this->view('accept_claimed', ['pageTitle' => 'Badge de otra cuenta'], 403);
        }

        // Claim atómico: solo procede si el badge sigue 'pending' (evita
        // doble-claim por carreras).
        $db      = Database::getInstance();
        $updated = $db->query(
            "UPDATE issued_badges SET status = 'accepted', accepted_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [(int) $badge['id']]
        )->rowCount();

        if ($updated === 0) {
            return Response::redirect('/earner/' . (string) $badge['earner_uuid']);
        }

        Logger::audit('badge.accepted', null, 'issued_badge', (string) $badge['uuid'], ['earner' => $badge['earner_uuid']]);
        return Response::redirect('/earner/' . (string) $badge['earner_uuid'] . '?accepted=1');
    }

    /**
     * Valida el token y devuelve el badge, o una Response de error.
     *
     * @return array<string,mixed>|Response
     */
    private function resolveBadge(string $token): array|Response
    {
        if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            return $this->view('accept_invalid', ['pageTitle' => 'Enlace inválido'], 404);
        }
        $badge = IssuedBadge::findByAcceptToken(hash('sha256', $token));
        if ($badge === null) {
            return $this->view('accept_invalid', ['pageTitle' => 'Enlace inválido'], 404);
        }
        if ($badge['status'] === 'revoked') {
            return $this->view('accept_invalid', ['pageTitle' => 'Badge revocado', 'revoked' => true], 410);
        }
        if ($badge['status'] === 'pending'
            && !empty($badge['accept_token_expires'])
            && strtotime((string) $badge['accept_token_expires']) < time()) {
            return $this->view('accept_invalid', ['pageTitle' => 'Enlace expirado', 'expired' => true], 410);
        }
        return $badge;
    }
}
