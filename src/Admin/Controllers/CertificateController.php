<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Zip;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Font;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\CertificateService;
use HexBadge\Services\ImageService;

/**
 * Configuración del certificado de un template: marcado de posiciones
 * (nombre, QR, ID, fecha, curso) sobre la plantilla de imagen.
 */
final class CertificateController extends Controller
{
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
        if (!empty($template['certificate_template_id'])) {
            Session::flash('error', 'Esta acreditación usa una plantilla de diploma guardada. Editá el diseño en Plantillas de diplomas.');
            return $this->redirect('/admin/templates/' . $uuid);
        }
        if (empty($template['certificate_filename'])) {
            Session::flash('error', 'Primero subí una imagen de plantilla de certificado en el template.');
            return $this->redirect('/admin/templates/' . $uuid . '/edit');
        }

        return $this->view('badges/certificate_config', [
            'pageTitle'     => 'Certificado — ' . $template['name'],
            'fonts'         => Font::allOrdered(),
            'config'        => $template['certificate_config'] ?: '{}',
            'imageUrl'      => public_url('uploads/certificates/' . (string) $template['certificate_filename']),
            'saveUrl'       => '/admin/templates/' . $uuid . '/certificate',
            'backUrl'       => '/admin/templates/' . $uuid,
            'backLabel'     => 'Volver al template',
            'heading'       => 'Certificado — ' . $template['name'],
            'subjectName'   => (string) $template['name'],
            'deleteUrl'     => '/admin/templates/' . $uuid . '/certificate/delete',
            'deleteLabel'   => 'Quitar certificado del template',
            'deleteConfirm' => '¿Quitar el certificado de este template?',
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
        $config = CertificateService::sanitizeConfig(is_array($parsed) ? $parsed : []);

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

    /**
     * GET /admin/templates/{uuid}/certificates — pantalla para elegir y
     * descargar diplomas en lote (un PDF con todos, o un ZIP por separado).
     */
    public function downloadIndex(Request $request, string $uuid): Response
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
        if (!CertificateService::hasCertificate(BadgeTemplate::withEffectiveCert($template))) {
            Session::flash('error', 'Este template no tiene un certificado configurado.');
            return $this->redirect('/admin/templates/' . $uuid);
        }

        $search  = trim((string) $request->query('q', ''));
        $filters = ['template_id' => (int) $template['id']];
        if ($search !== '') {
            $filters['q'] = $search;
        }
        $badges = array_values(array_filter(
            IssuedBadge::listForAdmin($filters, 5000),
            static fn (array $b): bool => ($b['status'] ?? '') !== 'revoked'
        ));

        return $this->view('badges/certificates_download', [
            'pageTitle' => 'Diplomas — ' . $template['name'],
            'template'  => $template,
            'badges'    => $badges,
            'search'    => $search,
        ]);
    }

    /**
     * POST /admin/templates/{uuid}/certificates — genera y descarga los
     * diplomas seleccionados (o todos) como un PDF único o un ZIP.
     */
    public function downloadBundle(Request $request, string $uuid): Response
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
        if (!CertificateService::hasCertificate(BadgeTemplate::withEffectiveCert($template))) {
            Session::flash('error', 'Este template no tiene un certificado configurado.');
            return $this->redirect('/admin/templates/' . $uuid);
        }

        // UUIDs válidos del template (no revocados): blinda contra que se
        // pidan diplomas de otro template/empresa pasando UUIDs ajenos.
        $valid = [];
        foreach (IssuedBadge::listForAdmin(['template_id' => (int) $template['id']], 5000) as $b) {
            if (($b['status'] ?? '') !== 'revoked') {
                $valid[(string) $b['uuid']] = true;
            }
        }

        if ($request->input('all') === '1') {
            $selected = array_keys($valid);
        } else {
            // OJO: input() solo devuelve strings; para badges[] (array) hay que
            // leer del cuerpo crudo con all().
            $req      = $request->all()['badges'] ?? [];
            $req      = is_array($req) ? $req : [];
            $selected = array_values(array_filter($req, static fn ($u): bool => isset($valid[(string) $u])));
        }
        if ($selected === []) {
            Session::flash('error', 'Seleccioná al menos un diploma.');
            return $this->redirect('/admin/templates/' . $uuid . '/certificates');
        }

        $svc  = new CertificateService();
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $template['name']), '-') ?: 'diplomas';

        if ($request->input('format') === 'zip') {
            $files = $svc->bundleFiles($selected);
            if ($files === []) {
                Session::flash('error', 'No se pudieron generar los diplomas.');
                return $this->redirect('/admin/templates/' . $uuid . '/certificates');
            }
            Logger::audit('template.certificates.downloaded', Auth::id(), 'badge_template', $uuid, ['format' => 'zip', 'count' => count($files)]);
            $bytes = Zip::create($files);
            return new Response($bytes, 200, [
                'Content-Type'        => 'application/zip',
                'Content-Disposition' => 'attachment; filename="diplomas-' . $slug . '.zip"',
                'Content-Length'      => (string) strlen($bytes),
            ]);
        }

        $pdf = $svc->bundlePdf($selected);
        if ($pdf === null) {
            Session::flash('error', 'No se pudieron generar los diplomas.');
            return $this->redirect('/admin/templates/' . $uuid . '/certificates');
        }
        Logger::audit('template.certificates.downloaded', Auth::id(), 'badge_template', $uuid, ['format' => 'pdf', 'count' => count($selected)]);
        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="diplomas-' . $slug . '.pdf"',
            'Content-Length'      => (string) strlen($pdf),
        ]);
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
}
