<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\View;
use HexBadge\Earner\EarnerAuth;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\CertificateService;
use HexBadge\Services\OpenBadgeService;

/**
 * Portal público de verificación + endpoints Open Badges (CLAUDE.md §5, §6.5).
 *
 * Vive en el dominio público (el de las personas), NO en el de admin. No
 * requiere autenticación; protegido por rate limiting por IP.
 */
final class VerifyController
{
    private function rateLimit(Request $request): ?Response
    {
        $limiter = new RateLimiter();
        $max     = (int) config('rate_limit.verify', 30);
        if (!$limiter->check($request->ip(), 'verify', $max, 60)) {
            return Response::text('Demasiadas solicitudes. Esperá un momento.', 429);
        }
        return null;
    }

    /**
     * GET /verify/{uuid} — página HTML de verificación.
     */
    public function show(Request $request, string $uuid): Response
    {
        if ($r = $this->rateLimit($request)) {
            return $r;
        }
        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null) {
            return Response::html(View::renderPartial('verify/not_found', ['appName' => config('app.name')]), 404);
        }

        $expired   = !empty($badge['expires_at']) && strtotime((string) $badge['expires_at']) < time();
        $verifyUrl = public_url('verify/' . $uuid);
        $issuedTs  = strtotime((string) $badge['issued_at']) ?: time();

        $linkedinParams = [
            'startTask'        => 'CERTIFICATION_NAME',
            'name'             => (string) $badge['template_name'],
            'organizationName' => (string) $badge['issuer_name'],
            'issueYear'        => date('Y', $issuedTs),
            'issueMonth'       => date('n', $issuedTs),
            'certUrl'          => $verifyUrl,
            'certId'           => $uuid,
        ];
        $orgId = (string) ($badge['linkedin_org_id'] ?? '');
        if ($orgId !== '') {
            $linkedinParams['organizationId'] = $orgId;
        }
        if (!empty($badge['expires_at'])) {
            $expTs = strtotime((string) $badge['expires_at']) ?: time();
            $linkedinParams['expirationYear']  = date('Y', $expTs);
            $linkedinParams['expirationMonth'] = date('n', $expTs);
        }

        // El botón "Agregar a LinkedIn" / compartir solo se ofrece al DUEÑO
        // autenticado del badge; un tercero solo ve el badge y su información.
        $isOwner = EarnerAuth::check() && EarnerAuth::id() === (int) $badge['earner_id'];

        $html = View::renderPartial('verify/show', [
            'appName'         => config('app.name'),
            'badge'           => $badge,
            'isOwner'         => $isOwner,
            'tags'            => BadgeTemplate::decodeTags($badge['skills_tags'] ?? null),
            'expired'         => $expired,
            'verifyUrl'       => $verifyUrl,
            'jsonUrl'         => $verifyUrl . '.json',
            'imageUrl'        => badge_image_url((string) $badge['image_filename']),
            'logoUrl'         => !empty($badge['logo_filename']) ? logo_image_url((string) $badge['logo_filename']) : null,
            'addToProfileUrl' => 'https://www.linkedin.com/profile/add?' . http_build_query($linkedinParams),
            'shareUrl'        => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($verifyUrl),
            'certificateUrl'  => (($badge['status'] ?? '') !== 'revoked' && CertificateService::hasCertificate($badge))
                ? public_url('certificate/' . $uuid . '.pdf') : null,
        ]);
        return Response::html($html);
    }

    /**
     * GET /verify/{uuid}.json — assertion Open Badge (se reconstruye en vivo
     * para reflejar siempre el estado y el dominio público actual).
     */
    public function assertionJson(Request $request, string $uuid): Response
    {
        if ($r = $this->rateLimit($request)) {
            return $r;
        }
        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json((new OpenBadgeService())->buildAssertion($badge));
    }

    /**
     * GET /badges/{uuid} — BadgeClass JSON-LD del template.
     */
    public function badgeClass(Request $request, string $uuid): Response
    {
        if ($r = $this->rateLimit($request)) {
            return $r;
        }
        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json((new OpenBadgeService())->buildBadgeClass($template));
    }

    /**
     * GET /issuer — Issuer Profile JSON-LD.
     */
    public function issuer(Request $request): Response
    {
        return Response::json((new OpenBadgeService())->buildIssuer());
    }
}
