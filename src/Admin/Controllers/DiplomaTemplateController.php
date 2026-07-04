<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Models\DiplomaTemplate;
use HexBadge\Models\Font;
use HexBadge\Services\CertificateService;
use HexBadge\Services\ImageService;
use InvalidArgumentException;

/**
 * CRUD de plantillas de diplomas reutilizables. Cada plantilla es una imagen
 * base + el marcado de posiciones (mismo formato que el certificado de un
 * template). Las acreditaciones las referencian en vivo.
 */
final class DiplomaTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        return $this->view('diplomas/index', [
            'pageTitle' => 'Plantillas de diplomas',
            'diplomas'  => DiplomaTemplate::listForAdmin($this->companyFilter($request)),
            'showCompany' => count($this->companiesForSelector()) > 1,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        return $this->view('diplomas/form', [
            'pageTitle' => 'Nueva plantilla de diploma',
            'diploma'   => null,
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
            $name      = $this->validName($request);
            $companyId = $this->companyForWrite($request);
            if ($companyId !== null && !$this->isCompanyAllowed($companyId)) {
                throw new InvalidArgumentException('Empresa inválida.');
            }

            $file = $request->file('image');
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Subí una imagen de plantilla (PNG/JPG).');
            }
            $filename = (new ImageService())->processCertificateUpload($file);

            $uuid = uuid4();
            DiplomaTemplate::create([
                'uuid'           => $uuid,
                'company_id'     => $companyId,
                'created_by'     => (int) Auth::id(),
                'name'           => $name,
                'image_filename' => $filename,
            ]);
            Logger::audit('diploma_template.created', Auth::id(), 'diploma_template', $uuid, ['name' => $name]);
            Session::flash('success', 'Plantilla creada. Marcá dónde van los datos.');
            return $this->redirect('/admin/diploma-templates/' . $uuid . '/mark');
        } catch (InvalidArgumentException $e) {
            return $this->view('diplomas/form', [
                'pageTitle' => 'Nueva plantilla de diploma',
                'diploma'   => $request->all(),
                'errors'    => [$e->getMessage()],
                'companies' => $this->companiesForSelector(),
            ], 422);
        }
    }

    public function edit(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $diploma = $this->findOwned($uuid);
        if ($diploma instanceof Response) {
            return $diploma;
        }
        return $this->view('diplomas/form', [
            'pageTitle' => 'Editar plantilla de diploma',
            'diploma'   => $diploma,
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
        $diploma = $this->findOwned($uuid);
        if ($diploma instanceof Response) {
            return $diploma;
        }

        try {
            $data = ['name' => $this->validName($request)];

            // Reemplazo opcional de la imagen: obliga a re-marcar.
            $file = $request->file('image');
            $reMark = false;
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $imgSvc = new ImageService();
                $data['image_filename'] = $imgSvc->processCertificateUpload($file);
                if (!empty($diploma['image_filename'])) {
                    $imgSvc->deleteCertificate((string) $diploma['image_filename']);
                }
                $data['config'] = null;
                $reMark = true;
            }

            DiplomaTemplate::updateById((int) $diploma['id'], $data);
            Logger::audit('diploma_template.updated', Auth::id(), 'diploma_template', $uuid, []);
            if ($reMark) {
                Session::flash('success', 'Plantilla actualizada. Volvé a marcar las posiciones sobre la nueva imagen.');
                return $this->redirect('/admin/diploma-templates/' . $uuid . '/mark');
            }
            Session::flash('success', 'Plantilla actualizada.');
            return $this->redirect('/admin/diploma-templates');
        } catch (InvalidArgumentException $e) {
            return $this->view('diplomas/form', [
                'pageTitle' => 'Editar plantilla de diploma',
                'diploma'   => array_merge($diploma, $request->all()),
                'errors'    => [$e->getMessage()],
                'companies' => $this->companiesForSelector(),
            ], 422);
        }
    }

    /** Pantalla de marcado (reutiliza la vista del certificado). */
    public function mark(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $diploma = $this->findOwned($uuid);
        if ($diploma instanceof Response) {
            return $diploma;
        }
        if (empty($diploma['image_filename'])) {
            Session::flash('error', 'Esta plantilla no tiene imagen.');
            return $this->redirect('/admin/diploma-templates/' . $uuid . '/edit');
        }

        return $this->view('badges/certificate_config', [
            'pageTitle'   => 'Diploma — ' . $diploma['name'],
            'fonts'       => Font::allOrdered(),
            'config'      => $diploma['config'] ?: '{}',
            'imageUrl'    => public_url('uploads/certificates/' . (string) $diploma['image_filename']),
            'saveUrl'     => '/admin/diploma-templates/' . $uuid . '/mark',
            'backUrl'     => '/admin/diploma-templates',
            'backLabel'   => 'Volver a plantillas',
            'heading'     => 'Diploma — ' . $diploma['name'],
            'subjectName' => (string) $diploma['name'],
            'deleteUrl'   => null,
        ]);
    }

    public function save(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);
        $diploma = $this->findOwned($uuid);
        if ($diploma instanceof Response) {
            return $diploma;
        }

        $parsed = json_decode((string) $request->input('config', '{}'), true);
        $config = CertificateService::sanitizeConfig(is_array($parsed) ? $parsed : []);

        foreach (['name', 'qr', 'cert_id', 'date'] as $req) {
            if (!isset($config[$req])) {
                Session::flash('error', 'Faltan marcas requeridas: nombre, QR, ID y fecha.');
                return $this->redirect('/admin/diploma-templates/' . $uuid . '/mark');
            }
        }

        DiplomaTemplate::updateById((int) $diploma['id'], ['config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
        Logger::audit('diploma_template.configured', Auth::id(), 'diploma_template', $uuid, []);
        Session::flash('success', 'Plantilla de diploma configurada.');
        return $this->redirect('/admin/diploma-templates');
    }

    public function delete(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);
        $diploma = $this->findOwned($uuid);
        if ($diploma instanceof Response) {
            return $diploma;
        }

        // Referencia viva: si alguna acreditación la usa, no se puede borrar
        // (dejaría esos diplomas sin imagen). Hay que desvincularlas primero.
        $uses = DiplomaTemplate::usageCount((int) $diploma['id']);
        if ($uses > 0) {
            Session::flash('error', "No se puede borrar: {$uses} acreditación(es) la usan. Cambiá su diploma primero.");
            return $this->redirect('/admin/diploma-templates');
        }

        if (!empty($diploma['image_filename'])) {
            (new ImageService())->deleteCertificate((string) $diploma['image_filename']);
        }
        $this->db()->query('DELETE FROM diploma_templates WHERE id = ?', [(int) $diploma['id']]);
        Logger::audit('diploma_template.deleted', Auth::id(), 'diploma_template', $uuid, []);
        Session::flash('success', 'Plantilla de diploma eliminada.');
        return $this->redirect('/admin/diploma-templates');
    }

    /** Nombre validado (1–150). */
    private function validName(Request $request): string
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('El nombre es requerido (máx 150 caracteres).');
        }
        return $name;
    }

    /**
     * Busca la plantilla y verifica acceso por empresa. Devuelve la fila o una
     * Response de error (404/403) que el caller debe retornar.
     *
     * @return array<string,mixed>|Response
     */
    private function findOwned(string $uuid): array|Response
    {
        $diploma = DiplomaTemplate::findByUuid($uuid);
        if ($diploma === null) {
            return Response::html('<h1>404 — Plantilla no encontrada</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($diploma['company_id']) ? (int) $diploma['company_id'] : null)) {
            return $r;
        }
        return $diploma;
    }
}
