<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;

/**
 * Wallet pública del receptor: grid de badges aceptados (CLAUDE.md §6.6).
 */
final class WalletController extends EarnerBaseController
{
    public function show(Request $request, string $uuid): Response
    {
        $earner = Earner::findByUuid($uuid);
        if ($earner === null) {
            return $this->view('wallet_not_found', ['pageTitle' => 'Perfil no encontrado'], 404);
        }

        $badges = IssuedBadge::acceptedForEarner((int) $earner['id']);

        // Decodificar tags de cada badge para la vista.
        foreach ($badges as &$b) {
            $b['tags'] = BadgeTemplate::decodeTags($b['skills_tags'] ?? null);
        }
        unset($b);

        // Pendientes por aceptar: solo si el DUEÑO está viendo su propio perfil
        // (la wallet es pública; los pendientes son privados).
        $isOwner = EarnerAuth::check() && EarnerAuth::id() === (int) $earner['id'];
        $pending = $isOwner ? IssuedBadge::pendingForEarner((int) $earner['id']) : [];

        return $this->view('wallet', [
            'pageTitle'    => (string) $earner['display_name'],
            'earner'       => $earner,
            'badges'       => $badges,
            'pending'      => $pending,
            'isOwner'      => $isOwner,
            'justAccepted' => $request->query('accepted') === '1',
            'verifyBase'   => public_url('verify/'),
        ]);
    }
}
