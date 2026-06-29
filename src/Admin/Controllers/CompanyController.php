<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Crypto;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\Company;
use HexBadge\Services\EmailService;
use InvalidArgumentException;

/**
 * Gestión de Empresas (tenants).
 *
 * - Crear/listar empresas: solo superadmin.
 * - Editar UNA empresa (datos del emisor + SMTP propio): superadmin (cualquiera)
 *   o el admin de esa empresa (la suya, vía /admin/company).
 */
final class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        return $this->view('companies/index', [
            'pageTitle' => 'Empresas',
            'companies' => Company::allOrdered(),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        return $this->view('companies/form', [
            'pageTitle' => 'Nueva empresa',
            'company'   => null,
            'errors'    => [],
        ]);
    }

    public function store(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        try {
            $data = $this->validateInput($request);
            $uuid = uuid4();
            $pass = (string) $request->input('smtp_password', '');
            if ($pass !== '') {
                $data['smtp_password'] = Crypto::encrypt($pass);
            }
            Company::create(array_merge($data, ['uuid' => $uuid]));
            Logger::audit('company.created', Auth::id(), 'company', $uuid, ['name' => $data['name']]);
            Session::flash('success', 'Empresa creada.');
            return $this->redirect('/admin/companies');
        } catch (InvalidArgumentException $e) {
            return $this->view('companies/form', [
                'pageTitle' => 'Nueva empresa',
                'company'   => $request->all(),
                'errors'    => [$e->getMessage()],
            ], 422);
        }
    }

    /** GET /admin/company — el admin edita SU propia empresa. */
    public function mine(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $cid = Auth::companyId();
        $company = $cid !== null ? Company::find($cid) : null;
        if ($company === null) {
            return Response::html('<h1>No tenés una empresa asignada.</h1>', 404);
        }
        return $this->view('companies/form', [
            'pageTitle' => 'Mi empresa',
            'company'   => $company,
            'errors'    => [],
        ]);
    }

    public function edit(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $company = Company::findByUuid($uuid);
        if ($company === null) {
            return Response::html('<h1>404 — Empresa no encontrada</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess((int) $company['id'])) {
            return $r;
        }
        return $this->view('companies/form', [
            'pageTitle' => 'Editar ' . $company['name'],
            'company'   => $company,
            'errors'    => [],
        ]);
    }

    public function update(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $company = Company::findByUuid($uuid);
        if ($company === null) {
            return Response::html('<h1>404 — Empresa no encontrada</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess((int) $company['id'])) {
            return $r;
        }

        try {
            $data = $this->validateInput($request);

            // La contraseña SMTP solo se actualiza si se ingresó una nueva.
            $pass = (string) $request->input('smtp_password', '');
            if ($pass !== '') {
                $data['smtp_password'] = Crypto::encrypt($pass);
            }

            Company::updateById((int) $company['id'], $data);
            Logger::audit('company.updated', Auth::id(), 'company', $uuid, []);
            Session::flash('success', 'Empresa actualizada.');
            return $this->redirect(Auth::isSuperadmin() ? '/admin/companies' : '/admin/company');
        } catch (InvalidArgumentException $e) {
            return $this->view('companies/form', [
                'pageTitle' => 'Editar ' . $company['name'],
                'company'   => array_merge($company, $request->all()),
                'errors'    => [$e->getMessage()],
            ], 422);
        }
    }

    /** POST /admin/companies/{uuid}/smtp-test — prueba el SMTP de la empresa. */
    public function smtpTest(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $company = Company::findByUuid($uuid);
        if ($company === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess((int) $company['id'])) {
            return $r;
        }

        $back = Auth::isSuperadmin() ? '/admin/companies/' . $uuid . '/edit' : '/admin/company';
        try {
            $to = (new Validator())->email((string) $request->input('test_email', ''));
            (new EmailService())->sendTest($to, (int) $company['id']);
            Session::flash('success', 'Correo de prueba enviado a ' . $to . '.');
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo enviar la prueba: ' . $e->getMessage());
        }
        return $this->redirect($back);
    }

    /**
     * Valida los datos editables de la empresa (sin la contraseña SMTP, que se
     * maneja aparte por el "dejar vacío = mantener").
     *
     * @return array<string,mixed>
     */
    private function validateInput(Request $request): array
    {
        $v = new Validator();
        $smtpHost = trim((string) $request->input('smtp_host', ''));

        $data = [
            'name'            => $v->name((string) $request->input('name', ''), 200),
            'issuer_url'      => trim((string) $request->input('issuer_url', '')) !== ''
                ? $v->url((string) $request->input('issuer_url', ''), true) : null,
            'issuer_email'    => trim((string) $request->input('issuer_email', '')) !== ''
                ? $v->email((string) $request->input('issuer_email', '')) : null,
            'linkedin_org_id' => (preg_replace('/\D+/', '', (string) $request->input('linkedin_org_id', '')) ?: null),
            // SMTP propio (vacío = usa el global).
            'smtp_host'         => $smtpHost !== '' ? $smtpHost : null,
            'smtp_port'         => $smtpHost !== '' ? $v->int((string) $request->input('smtp_port', '587'), 1, 65535) : null,
            'smtp_username'     => trim((string) $request->input('smtp_username', '')) ?: null,
            'smtp_encryption'   => $v->inList((string) $request->input('smtp_encryption', 'tls'), ['tls', 'ssl', 'none'], 'cifrado'),
            'smtp_from_address' => trim((string) $request->input('smtp_from_address', '')) !== ''
                ? $v->email((string) $request->input('smtp_from_address', '')) : null,
            'smtp_from_name'    => trim((string) $request->input('smtp_from_name', '')) ?: null,
        ];

        // is_active solo lo cambia el superadmin (un admin no desactiva su empresa).
        if (Auth::isSuperadmin()) {
            $data['is_active'] = (int) ((string) $request->input('is_active', '1') === '1');
        }

        return $data;
    }
}
