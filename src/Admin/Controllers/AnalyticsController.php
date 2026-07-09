<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Models\IssuedBadge;

/**
 * Analytics y exportación (CLAUDE.md §6.7).
 */
final class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }

        $cf    = $this->companyFilter($request);
        $cfBt  = $cf !== null ? ' AND bt.company_id = ?' : '';
        $cfArg = $cf !== null ? [$cf] : [];

        $byMonth = $this->db()->fetchAll(
            "SELECT DATE_FORMAT(ib.issued_at, '%Y-%m') AS month, COUNT(*) AS total
             FROM issued_badges ib JOIN badge_templates bt ON bt.id = ib.badge_template_id
             WHERE ib.issued_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . $cfBt . "
             GROUP BY month ORDER BY month ASC",
            $cfArg
        );

        $acceptance = $this->db()->fetchAll(
            "SELECT bt.name,
                    COUNT(*) AS issued,
                    SUM(ib.status = 'accepted') AS accepted
             FROM issued_badges ib JOIN badge_templates bt ON bt.id = ib.badge_template_id
             WHERE 1=1" . $cfBt . "
             GROUP BY bt.id, bt.name ORDER BY issued DESC LIMIT 10",
            $cfArg
        );

        $topEarners = $this->db()->fetchAll(
            "SELECT e.display_name, e.email, COUNT(*) AS total
             FROM issued_badges ib
             JOIN earners e ON e.id = ib.earner_id
             JOIN badge_templates bt ON bt.id = ib.badge_template_id
             WHERE 1=1" . $cfBt . "
             GROUP BY e.id ORDER BY total DESC LIMIT 10",
            $cfArg
        );

        return $this->view('analytics/index', [
            'pageTitle'  => 'Analytics',
            'byMonth'    => $byMonth,
            'acceptance' => $acceptance,
            'topEarners' => $topEarners,
        ]);
    }

    /**
     * GET /admin/analytics/export — CSV de todos los badges (con filtros).
     */
    public function export(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }

        $filters = [
            'status'      => $request->query('status', ''),
            'template_id' => $request->query('template', ''),
            'from'        => $request->query('from', ''),
            'to'          => $request->query('to', ''),
            'company_id'  => $this->companyFilter($request),
        ];
        $badges = IssuedBadge::listForAdmin($filters);

        $out = "badge_uuid,receptor,email,template,estado,via,emitido,expira\n";
        foreach ($badges as $b) {
            $out .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s\n",
                $b['uuid'],
                $this->csv((string) $b['earner_name']),
                $this->csv((string) $b['earner_email']),
                $this->csv((string) $b['template_name']),
                $b['status'],
                $b['issued_via'],
                $b['issued_at'],
                (string) ($b['expires_at'] ?? '')
            );
        }

        return new Response($out, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="badges_' . date('Ymd') . '.csv"',
        ]);
    }

    private function csv(string $v): string
    {
        // Neutraliza formula/CSV injection: un valor que empieza con = + - @ (o
        // tab/CR) se interpretaría como fórmula en Excel/Sheets. Se prefija con '.
        if ($v !== '' && str_contains("=+-@\t\r", $v[0])) {
            $v = "'" . $v;
        }
        return str_replace([',', "\n", "\r"], [' ', ' ', ' '], $v);
    }
}
