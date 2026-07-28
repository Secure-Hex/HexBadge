<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Services\ImageOptimizerService;

/**
 * Tareas de mantenimiento que en un hosting compartido no se pueden correr por
 * consola. Solo superadmin: reescriben archivos y filas.
 */
final class MaintenanceController extends Controller
{
    /** Presupuesto por petición; el resto queda para la siguiente pasada. */
    private const TIME_BUDGET = 20;

    public function images(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }

        return $this->view('settings/images', [
            'pageTitle' => 'Optimizar imágenes',
            'report'    => (new ImageOptimizerService())->inspect(),
            'result'    => null,
        ]);
    }

    public function optimizeImages(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        // Un lote puede pasarse del límite por defecto de PHP.
        @set_time_limit(self::TIME_BUDGET + 40);

        $svc    = new ImageOptimizerService();
        $result = $svc->run(self::TIME_BUDGET);

        Logger::audit('maintenance.images_optimized', (int) Auth::id(), null, null, [
            'processed' => $result['processed'],
            'failed'    => $result['failed'],
            'remaining' => $result['remaining'],
            'saved_kb'  => (int) (($result['before'] - $result['after']) / 1024),
        ]);

        if ($result['processed'] > 0 && $result['remaining'] === 0) {
            Session::flash('success', 'Listo: no quedan imágenes por optimizar.');
        } elseif ($result['remaining'] > 0) {
            Session::flash('success', sprintf(
                'Optimizadas %d. Quedan %d: volvé a ejecutar para continuar.',
                $result['processed'],
                $result['remaining']
            ));
        }
        if ($result['failed'] > 0) {
            Session::flash('error', $result['failed'] . ' imagen(es) no se pudieron convertir; se conservaron como estaban.');
        }

        return $this->view('settings/images', [
            'pageTitle' => 'Optimizar imágenes',
            'report'    => $svc->inspect(),
            'result'    => $result,
        ]);
    }
}
