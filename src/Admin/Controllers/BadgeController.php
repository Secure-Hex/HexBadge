<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\BadgeService;

/**
 * Listado, detalle y revocación de badges emitidos (CLAUDE.md §6.7, §7).
 */
final class BadgeController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $companyFilter = $this->companyFilter($request);
        $filters = [
            'status'      => $request->query('status', ''),
            'template_id' => $request->query('template', ''),
            'q'           => trim((string) $request->query('q', '')),
            'company_id'  => $companyFilter,
        ];

        $allowedSort = ['receptor', 'email', 'badge', 'empresa', 'via', 'emitido', 'aceptado', 'estado'];
        $sort = (string) $request->query('sort', 'emitido');
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'emitido';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage    = $this->perPage($request);
        $total      = IssuedBadge::countForAdmin($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) $request->query('page', '1')), $totalPages);
        $offset     = ($page - 1) * $perPage;

        return $this->view('badges/badges_index', [
            'pageTitle'     => 'Badges emitidos',
            'badges'        => IssuedBadge::listForAdmin($filters, $perPage, $offset, $sort, $dir),
            'sort'          => $sort,
            'dir'           => $dir,
            'templates'     => BadgeTemplate::listForAdmin($companyFilter),
            'filters'       => $filters,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'perPage'       => $perPage,
            'companies'     => $this->companiesForSelector(),
            'companyFilter' => $companyFilter,
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null) {
            return Response::html('<h1>404 — Badge no encontrado</h1>', 404);
        }
        if ($r = $this->assertCompanyAccess(isset($badge['company_id']) ? (int) $badge['company_id'] : null)) {
            return $r;
        }
        return $this->view('badges/badge_show', [
            'pageTitle' => 'Badge ' . substr($uuid, 0, 8),
            'badge'     => $badge,
            'verifyUrl' => public_url('verify/' . $uuid),
        ]);
    }

    public function revoke(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) { // revocar requiere admin+
            return $r;
        }
        $this->verifyCsrf($request);

        if ($r = $this->assertBadgeCompany($uuid)) {
            return $r;
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            $reason = 'Sin motivo especificado';
        }

        $ok = (new BadgeService())->revoke($uuid, $reason, (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $ok ? 'Badge revocado.' : 'No se pudo revocar el badge.');
        return $this->redirect('/admin/badges/' . $uuid);
    }

    /**
     * Reenvía el correo de aceptación (genera un token nuevo e invalida el anterior).
     */
    public function resend(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        if ($r = $this->assertBadgeCompany($uuid)) {
            return $r;
        }

        $ok = (new BadgeService())->sendNotification($uuid);
        Session::flash($ok ? 'success' : 'error', $ok ? 'Correo reenviado.' : 'No se pudo reenviar el correo.');
        return $this->redirect('/admin/badges/' . $uuid);
    }

    /**
     * Valida que el badge pertenece a la empresa del usuario (o superadmin).
     */
    private function assertBadgeCompany(string $uuid): ?Response
    {
        $badge = IssuedBadge::findFullByUuid($uuid);
        if ($badge === null) {
            return Response::html('<h1>404 — Badge no encontrado</h1>', 404);
        }
        return $this->assertCompanyAccess(isset($badge['company_id']) ? (int) $badge['company_id'] : null);
    }
}
