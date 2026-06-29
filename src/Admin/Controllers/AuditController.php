<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;

/**
 * Vista del log de auditoría (CLAUDE.md §4.9, §7). Solo lectura.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }

        $action = trim((string) $request->query('action', ''));
        $params = [];
        $sql = "SELECT a.*, u.name AS user_name
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                WHERE 1=1";
        if ($action !== '') {
            $sql .= ' AND a.action = ?';
            $params[] = $action;
        }
        // Aislamiento por empresa (sub-admin ve solo la suya; superadmin, todo).
        $cf = $this->companyFilter($request);
        if ($cf !== null) {
            $sql .= ' AND a.company_id = ?';
            $params[] = $cf;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT 200';

        return $this->view('audit/index', [
            'pageTitle' => 'Auditoría',
            'logs'      => $this->db()->fetchAll($sql, $params),
            'action'    => $action,
        ]);
    }
}
