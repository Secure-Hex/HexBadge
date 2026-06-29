<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\CertificateService;

/**
 * Descarga pública del certificado/diploma en PDF.
 *
 * Vive en el dominio público. No requiere login (cualquiera con el enlace puede
 * descargarlo, igual que la página de verificación). Rate-limit por IP.
 */
final class CertificateController
{
    public function download(Request $request, string $uuid): Response
    {
        $limiter = new RateLimiter();
        if (!$limiter->check($request->ip(), 'certificate', (int) config('rate_limit.verify', 30), 60)) {
            return Response::text('Demasiadas solicitudes. Esperá un momento.', 429);
        }

        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null
            || ($badge['status'] ?? '') === 'revoked'
            || !CertificateService::hasCertificate($badge)) {
            return Response::html('<h1>404 — Certificado no disponible</h1>', 404);
        }

        $path = (new CertificateService())->generate($uuid);
        if ($path === null || !is_file($path)) {
            return Response::html('<h1>No se pudo generar el certificado</h1>', 500);
        }

        $bytes = (string) @file_get_contents($path);
        if ($bytes === '') {
            return Response::html('<h1>No se pudo generar el certificado</h1>', 500);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', 'certificado-' . (string) $badge['template_name']);

        return new Response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . trim((string) $name, '-') . '.pdf"',
            'Content-Length'      => (string) strlen($bytes),
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }
}
