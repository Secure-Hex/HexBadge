<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;

/**
 * Listado y detalle de earners (CLAUDE.md §7).
 */
final class EarnerController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $search        = trim((string) $request->query('q', ''));
        $companyFilter = $this->companyFilter($request);

        $perPage    = $this->perPage($request);
        $total      = Earner::countForAdmin($search, $companyFilter);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) $request->query('page', '1')), $totalPages);
        $offset     = ($page - 1) * $perPage;

        return $this->view('earner/index', [
            'pageTitle'     => 'Receptores',
            'earners'       => Earner::listForAdmin($search, $companyFilter, $perPage, $offset),
            'search'        => $search,
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
        $earner = Earner::findByUuid($uuid);
        if ($earner === null) {
            return Response::html('<h1>404 — Receptor no encontrado</h1>', 404);
        }

        // Un sub-admin solo ve los badges de SU empresa (la persona es global).
        $companyFilter = $this->companyFilter($request);
        $sql = "SELECT ib.uuid, ib.status, ib.issued_at, bt.name AS template_name, co.name AS company_name
                FROM issued_badges ib
                JOIN badge_templates bt ON bt.id = ib.badge_template_id
                LEFT JOIN companies co ON co.id = bt.company_id
                WHERE ib.earner_id = ?";
        $params = [(int) $earner['id']];
        if ($companyFilter !== null) {
            $sql .= ' AND bt.company_id = ?';
            $params[] = $companyFilter;
        }
        $sql .= ' ORDER BY ib.issued_at DESC';
        $badges = $this->db()->fetchAll($sql, $params);

        // Si un sub-admin abre un receptor que no tiene badges de su empresa → no existe para él.
        if ($badges === [] && !Auth::isSuperadmin()) {
            return Response::html('<h1>404 — Receptor no encontrado</h1>', 404);
        }

        return $this->view('earner/show', [
            'pageTitle'  => (string) $earner['display_name'],
            'earner'     => $earner,
            'badges'     => $badges,
            'walletUrl'  => rtrim((string) config('app.earner_url'), '/') . '/earner/' . $uuid,
        ]);
    }
}
