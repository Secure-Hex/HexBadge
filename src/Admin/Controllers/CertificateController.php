<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Font;
use HexBadge\Services\ImageService;

/**
 * Configuración del certificado de un template: marcado de posiciones
 * (nombre, QR, ID, fecha, curso) sobre la plantilla de imagen.
 */
final class CertificateController extends Controller
{
    private const TEXT_FIELDS = ['name', 'course', 'date', 'cert_id'];

    public function show(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::html('<h1>404 — Template no encontrado</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $r;
        }
        if (empty($template['certificate_filename'])) {
            Session::flash('error', 'Primero subí una imagen de plantilla de certificado en el template.');
            return $this->redirect('/admin/templates/' . $uuid . '/edit');
        }

        return $this->view('badges/certificate_config', [
            'pageTitle' => 'Certificado — ' . $template['name'],
            'template'  => $template,
            'fonts'     => Font::allOrdered(),
            'config'    => $template['certificate_config'] ?: '{}',
            'imageUrl'  => public_url('uploads/certificates/' . (string) $template['certificate_filename']),
        ]);
    }

    public function save(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $r;
        }

        $raw    = (string) $request->input('config', '{}');
        $parsed = json_decode($raw, true);
        $config = $this->sanitizeConfig(is_array($parsed) ? $parsed : []);

        foreach (['name', 'qr', 'cert_id', 'date'] as $req) {
            if (!isset($config[$req])) {
                Session::flash('error', 'Faltan marcas requeridas: nombre, QR, ID y fecha.');
                return $this->redirect('/admin/templates/' . $uuid . '/certificate');
            }
        }

        BadgeTemplate::updateById((int) $template['id'], ['certificate_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
        Logger::audit('template.certificate.configured', Auth::id(), 'badge_template', $uuid, []);
        Session::flash('success', 'Certificado configurado.');
        return $this->redirect('/admin/templates/' . $uuid);
    }

    public function delete(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $r;
        }
        if (!empty($template['certificate_filename'])) {
            (new ImageService())->deleteCertificate((string) $template['certificate_filename']);
        }
        BadgeTemplate::updateById((int) $template['id'], ['certificate_filename' => null, 'certificate_config' => null]);
        Session::flash('success', 'Certificado eliminado del template.');
        return $this->redirect('/admin/templates/' . $uuid);
    }

    /**
     * Normaliza el config: clampa fracciones 0–1, valida campos conocidos.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function sanitizeConfig(array $in): array
    {
        $out  = [];
        $frac = static fn (mixed $v): float => max(0.0, min(1.0, (float) $v));
        $hex  = static function (mixed $v): string {
            $v = ltrim((string) $v, '#');
            return preg_match('/^[0-9a-fA-F]{3,6}$/', $v) ? '#' . $v : '#1a2233';
        };
        $fontId = static fn (mixed $v): int => max(0, (int) $v);

        foreach (self::TEXT_FIELDS as $f) {
            if (!isset($in[$f]) || !is_array($in[$f])) {
                continue;
            }
            $b = $in[$f];
            $out[$f] = [
                'x'     => $frac($b['x'] ?? 0),
                'y'     => $frac($b['y'] ?? 0),
                'w'     => $frac($b['w'] ?? 0.3),
                'h'     => $frac($b['h'] ?? 0.05),
                'align' => in_array($b['align'] ?? '', ['left', 'center', 'right'], true) ? $b['align'] : 'center',
                'color' => $hex($b['color'] ?? '#1a2233'),
                'font'  => $fontId($b['font'] ?? 0),
            ];
            if ($f === 'date') {
                $out[$f]['format'] = in_array($b['format'] ?? '', ['long_es', 'short', 'long_en'], true) ? $b['format'] : 'long_es';
            }
        }

        if (isset($in['qr']) && is_array($in['qr'])) {
            $out['qr'] = [
                'x'    => $frac($in['qr']['x'] ?? 0.8),
                'y'    => $frac($in['qr']['y'] ?? 0.8),
                'size' => max(0.03, min(0.5, (float) ($in['qr']['size'] ?? 0.12))),
            ];
        }
        return $out;
    }
}
