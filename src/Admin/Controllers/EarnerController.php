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

        $allowedSort = ['nombre', 'email', 'badges', 'verificado'];
        $sort = (string) $request->query('sort', '');
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'reciente';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage    = $this->perPage($request);
        $total      = Earner::countForAdmin($search, $companyFilter);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) $request->query('page', '1')), $totalPages);
        $offset     = ($page - 1) * $perPage;

        return $this->view('earner/index', [
            'pageTitle'     => 'Receptores',
            'earners'       => Earner::listForAdmin($search, $companyFilter, $perPage, $offset, $sort, $dir),
            'sort'          => $sort,
            'dir'           => $dir,
            'search'        => $search,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'perPage'       => $perPage,
            'companies'     => $this->companiesForSelector(),
            'companyFilter' => $companyFilter,
        ]);
    }

    /**
     * GET /admin/earners/export — CSV de todos los receptores con sus badges.
     * Respeta el filtro de empresa: superadmin exporta a todos.
     */
    public function export(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $earners = Earner::exportForAdmin($this->companyFilter($request));

        $out = "nombre,email,verificado,acreditaciones\n";
        foreach ($earners as $e) {
            $out .= sprintf(
                "%s,%s,%s,%s\n",
                $this->csv((string) $e['display_name']),
                $this->csv((string) $e['email']),
                ((int) $e['is_verified'] === 1) ? 'si' : 'no',
                $this->csv((string) ($e['badges'] ?? ''))
            );
        }

        return new Response($out, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="receptores_' . date('Ymd') . '.csv"',
        ]);
    }

    private function csv(string $v): string
    {
        return str_replace([',', "\n", "\r"], [' ', ' ', ' '], $v);
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
