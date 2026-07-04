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
use HexBadge\Models\DiplomaTemplate;
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
            'pageTitle'         => 'Nuevo template',
            'template'          => null,
            'errors'            => [],
            'companies'         => $this->companiesForSelector(),
            'diplomaTemplates'  => DiplomaTemplate::listForAdmin($this->companyFilter($request)),
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

            // Diploma: ninguno / imagen propia / plantilla guardada (3 opciones).
            $new      = BadgeTemplate::findByUuid($uuid);
            $markUuid = $this->applyCertMode($request, $new);
            if ($markUuid !== null) {
                Session::flash('success', 'Template creado. Marcá dónde van los datos en el certificado.');
                return $this->redirect('/admin/templates/' . $markUuid . '/certificate');
            }

            Session::flash('success', 'Template creado.');
            return $this->redirect('/admin/templates/' . $uuid);
        } catch (InvalidArgumentException $e) {
            return $this->view('badges/template_form', [
                'pageTitle'        => 'Nuevo template',
                'template'         => $request->all(),
                'errors'           => [$e->getMessage()],
                'companies'        => $this->companiesForSelector(),
                'diplomaTemplates' => DiplomaTemplate::listForAdmin($this->companyFilter($request)),
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
            'pageTitle'        => 'Editar template',
            'template'         => $template,
            'errors'           => [],
            'companies'        => $this->companiesForSelector(),
            'diplomaTemplates' => DiplomaTemplate::listForAdmin($this->companyFilter($request)),
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

            BadgeTemplate::updateById((int) $template['id'], $data);

            // Diploma: ninguno / imagen propia / plantilla guardada (3 opciones).
            $markUuid = $this->applyCertMode($request, $template);
            Logger::audit('template.updated', Auth::id(), 'badge_template', $uuid, []);

            if ($markUuid !== null) {
                Session::flash('success', 'Template actualizado. Marcá las posiciones del nuevo certificado.');
                return $this->redirect('/admin/templates/' . $uuid . '/certificate');
            }
            Session::flash('success', 'Template actualizado.');
            return $this->redirect('/admin/templates/' . $uuid);
        } catch (InvalidArgumentException $e) {
            $template['skills_tags_csv'] = (string) $request->input('skills_tags', '');
            return $this->view('badges/template_form', [
                'pageTitle'        => 'Editar template',
                'template'         => array_merge($template, $request->all()),
                'errors'           => [$e->getMessage()],
                'companies'        => $this->companiesForSelector(),
                'diplomaTemplates' => DiplomaTemplate::listForAdmin($this->companyFilter($request)),
            ], 422);
        }
    }

    /**
     * Aplica el modo de diploma elegido en el form (cert_mode) a un template
     * existente. Referencia viva: si se elige una plantilla guardada, se guarda
     * el vínculo; si se sube imagen propia, se marca esa. Devuelve el uuid a
     * marcar (modo imagen propia recién subida) o null.
     *
     * @param array<string,mixed> $template Fila ACTUAL (para limpiar imagen previa).
     */
    private function applyCertMode(Request $request, array $template): ?string
    {
        $mode = (string) $request->input('cert_mode', 'none');
        $id   = (int) $template['id'];
        $img  = new ImageService();
        $ownFile = (string) ($template['certificate_filename'] ?? '');

        if ($mode === 'template') {
            $dtId = (int) $request->input('certificate_template_id', '0');
            $dt   = $dtId > 0 ? DiplomaTemplate::find($dtId) : null;
            if ($dt === null || ($dt['company_id'] !== null && !$this->isCompanyAllowed((int) $dt['company_id']))) {
                throw new InvalidArgumentException('Elegí una plantilla de diploma válida.');
            }
            if ($ownFile !== '') {
                $img->deleteCertificate($ownFile);
            }
            BadgeTemplate::updateById($id, [
                'certificate_template_id' => $dtId,
                'certificate_filename'    => null,
                'certificate_config'      => null,
            ]);
            return null;
        }

        if ($mode === 'upload') {
            $certFile = $request->file('certificate_image');
            if ($certFile !== null && ($certFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $certName = $img->processCertificateUpload($certFile);
                if ($ownFile !== '') {
                    $img->deleteCertificate($ownFile);
                }
                BadgeTemplate::updateById($id, [
                    'certificate_template_id' => null,
                    'certificate_filename'    => $certName,
                    'certificate_config'      => null,
                ]);
                return (string) $template['uuid'];
            }
            // "Subir imagen" sin archivo nuevo: si ya tenía imagen propia, se
            // mantiene tal cual; si venía vinculado, se desvincula.
            if ($ownFile === '' && !empty($template['certificate_template_id'])) {
                BadgeTemplate::updateById($id, ['certificate_template_id' => null]);
            }
            return null;
        }

        // 'none' → sin diploma: limpia imagen propia y vínculo.
        if ($ownFile !== '') {
            $img->deleteCertificate($ownFile);
        }
        BadgeTemplate::updateById($id, [
            'certificate_template_id' => null,
            'certificate_filename'    => null,
            'certificate_config'      => null,
        ]);
        return null;
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
