<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Services\ImageService;
use InvalidArgumentException;

/**
 * CRUD de templates de badges (CLAUDE.md §6.1).
 */
final class BadgeTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $companyFilter = $this->companyFilter($request);
        return $this->view('badges/templates_index', [
            'pageTitle'     => 'Templates',
            'templates'     => BadgeTemplate::listForAdmin($companyFilter),
            'companies'     => $this->companiesForSelector(),
            'companyFilter' => $companyFilter,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        return $this->view('badges/template_form', [
            'pageTitle' => 'Nuevo template',
            'template'  => null,
            'errors'    => [],
            'companies' => $this->companiesForSelector(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        try {
            $data  = $this->validateInput($request, true);

            $companyId = $this->companyForWrite($request);
            if ($companyId === null || !$this->isCompanyAllowed($companyId)) {
                throw new InvalidArgumentException('Seleccioná una empresa válida para el template.');
            }

            $image = new ImageService();
            $file  = $request->file('image');
            if ($file === null) {
                throw new InvalidArgumentException('La imagen del badge es requerida');
            }
            $filename = $image->processUpload($file);

            $uuid = uuid4();
            BadgeTemplate::create(array_merge($data, [
                'uuid'           => $uuid,
                'created_by'     => (int) Auth::id(),
                'company_id'     => $companyId,
                'image_filename' => $filename,
            ]));

            Logger::audit('template.created', Auth::id(), 'badge_template', $uuid, ['name' => $data['name']]);

            // Plantilla de certificado opcional: si se subió, ir al marcado.
            $certFile = $request->file('certificate_image');
            if ($certFile !== null && ($certFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $certName = $image->processCertificateUpload($certFile);
                $new      = BadgeTemplate::findByUuid($uuid);
                BadgeTemplate::updateById((int) $new['id'], ['certificate_filename' => $certName]);
                Session::flash('success', 'Template creado. Marcá dónde van los datos en el certificado.');
                return $this->redirect('/admin/templates/' . $uuid . '/certificate');
            }

            Session::flash('success', 'Template creado.');
            return $this->redirect('/admin/templates/' . $uuid);
        } catch (InvalidArgumentException $e) {
            return $this->view('badges/template_form', [
                'pageTitle' => 'Nuevo template',
                'template'  => $request->all(),
                'errors'    => [$e->getMessage()],
                'companies' => $this->companiesForSelector(),
            ], 422);
        }
    }

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
        return $this->view('badges/template_show', [
            'pageTitle' => $template['name'],
            'template'  => $template,
            'tags'      => BadgeTemplate::decodeTags($template['skills_tags'] ?? null),
        ]);
    }

    public function edit(Request $request, string $uuid): Response
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
        $template['skills_tags_csv'] = implode(', ', BadgeTemplate::decodeTags($template['skills_tags'] ?? null));
        return $this->view('badges/template_form', [
            'pageTitle' => 'Editar template',
            'template'  => $template,
            'errors'    => [],
            'companies' => $this->companiesForSelector(),
        ]);
    }

    public function update(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::html('<h1>404 — Template no encontrado</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $r;
        }

        try {
            $data = $this->validateInput($request, false);

            // Imagen opcional en edición; si se sube una nueva, reemplaza.
            $file = $request->file('image');
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $image       = new ImageService();
                $newFilename = $image->processUpload($file);
                $image->delete((string) $template['image_filename']);
                $data['image_filename'] = $newFilename;
            }

            // Plantilla de certificado opcional: si se sube una nueva, reemplaza
            // y se va al marcado.
            $certFile = $request->file('certificate_image');
            if ($certFile !== null && ($certFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $imgSvc   = new ImageService();
                $certName = $imgSvc->processCertificateUpload($certFile);
                if (!empty($template['certificate_filename'])) {
                    $imgSvc->deleteCertificate((string) $template['certificate_filename']);
                }
                $data['certificate_filename'] = $certName;
                $data['certificate_config']   = null; // re-marcar con la nueva imagen
            }

            BadgeTemplate::updateById((int) $template['id'], $data);
            Logger::audit('template.updated', Auth::id(), 'badge_template', $uuid, []);

            if (isset($data['certificate_filename'])) {
                Session::flash('success', 'Template actualizado. Marcá las posiciones del nuevo certificado.');
                return $this->redirect('/admin/templates/' . $uuid . '/certificate');
            }
            Session::flash('success', 'Template actualizado.');
            return $this->redirect('/admin/templates/' . $uuid);
        } catch (InvalidArgumentException $e) {
            $template['skills_tags_csv'] = (string) $request->input('skills_tags', '');
            return $this->view('badges/template_form', [
                'pageTitle' => 'Editar template',
                'template'  => array_merge($template, $request->all()),
                'errors'    => [$e->getMessage()],
                'companies' => $this->companiesForSelector(),
            ], 422);
        }
    }

    public function archive(Request $request, string $uuid): Response
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
        BadgeTemplate::updateById((int) $template['id'], ['state' => 'archived', 'is_active' => 0]);
        Logger::audit('template.archived', Auth::id(), 'badge_template', $uuid, []);
        Session::flash('success', 'Template archivado.');
        return $this->redirect('/admin/templates');
    }

    /**
     * Valida y normaliza el formulario de template.
     *
     * @return array<string,mixed>
     */
    private function validateInput(Request $request, bool $isCreate): array
    {
        $v = new Validator();

        $name        = $v->name((string) $request->input('name', ''), 200);
        $description = $v->text((string) $request->input('description', ''), 5000);
        $criteria    = $v->text((string) $request->input('criteria_text', ''), 5000);
        $criteriaUrl = $v->url((string) $request->input('criteria_url', ''), false);
        $expiresDays = $v->int((string) $request->input('expires_days', ''), 1, 3650, false);
        $isPublic    = $request->input('is_public', '1') === '1' ? 1 : 0;
        $state       = $v->inList((string) $request->input('state', 'draft'), ['draft', 'active', 'archived'], 'estado');

        // Tags: CSV -> array -> JSON.
        $tagsCsv = (string) $request->input('skills_tags', '');
        $tags    = array_values(array_filter(array_map('trim', explode(',', $tagsCsv)), static fn (string $t): bool => $t !== ''));

        // Los datos del emisor (issuer_*) ya NO se editan acá: viven en la Empresa.
        return [
            'name'          => $name,
            'description'   => $description,
            'criteria_text' => $criteria,
            'criteria_url'  => $criteriaUrl,
            'skills_tags'   => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
            'expires_days'  => $expiresDays,
            'is_public'     => $isPublic,
            'state'         => $state,
        ];
    }
}
