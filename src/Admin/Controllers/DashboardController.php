<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;

/**
 * Dashboard del panel admin (CLAUDE.md §6.7).
 */
final class DashboardController extends Controller
{
    /**
     * GET /admin — métricas resumidas.
     */
    public function index(Request $request): Response
    {
        if ($redirect = Auth::requireRole('issuer')) {
            return $redirect;
        }

        $now      = date('Y-m-01 00:00:00');
        $lastMon  = date('Y-m-01 00:00:00', strtotime('first day of last month'));

        // Filtro por empresa (sub-admin: la suya; superadmin: todas).
        $cf     = $this->companyFilter($request);
        $btJoin = ' JOIN badge_templates bt ON bt.id = ib.badge_template_id';
        $cfBt   = $cf !== null ? ' AND bt.company_id = ?' : '';
        $cfArg  = $cf !== null ? [$cf] : [];

        $issuedThisMonth = (int) $this->db()->fetchColumn(
            'SELECT COUNT(*) FROM issued_badges ib' . $btJoin . ' WHERE ib.issued_at >= ?' . $cfBt,
            array_merge([$now], $cfArg)
        );
        $issuedLastMonth = (int) $this->db()->fetchColumn(
            'SELECT COUNT(*) FROM issued_badges ib' . $btJoin . ' WHERE ib.issued_at >= ? AND ib.issued_at < ?' . $cfBt,
            array_merge([$lastMon, $now], $cfArg)
        );
        $pending = (int) $this->db()->fetchColumn(
            "SELECT COUNT(*) FROM issued_badges ib" . $btJoin . " WHERE ib.status = 'pending'" . $cfBt,
            $cfArg
        );

        $topTemplates = $this->db()->fetchAll(
            'SELECT name, badges_issued FROM badge_templates'
            . ($cf !== null ? ' WHERE company_id = ?' : '')
            . ' ORDER BY badges_issued DESC LIMIT 5',
            $cfArg
        );

        $recent = $this->db()->fetchAll(
            "SELECT ib.uuid, ib.issued_at, ib.status,
                    bt.name AS template_name, e.display_name AS earner_name
             FROM issued_badges ib
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             JOIN earners e ON e.id = ib.earner_id
             WHERE 1=1" . $cfBt . "
             ORDER BY ib.issued_at DESC LIMIT 10",
            $cfArg
        );

        return $this->view('dashboard/index', [
            'pageTitle'       => 'Dashboard',
            'issuedThisMonth' => $issuedThisMonth,
            'issuedLastMonth' => $issuedLastMonth,
            'pending'         => $pending,
            'topTemplates'    => $topTemplates,
            'recent'          => $recent,
        ]);
    }
}
