<?php

/**
 * Front controller del PANEL ADMIN de HexBadge.
 *
 * Docroot: apps/admin/public/. Aloja el panel de administración, los
 * endpoints públicos de Open Badges (/verify, /badges, /issuer) y la API.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use HexBadge\Core\Installer;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Router;
use HexBadge\Core\Session;
use HexBadge\Core\View;
use HexBadge\Admin\Controllers\AuthController;
use HexBadge\Admin\Controllers\DashboardController;
use HexBadge\Admin\Controllers\InstallController;

View::setBasePath(BASE_PATH . '/src/Admin/Views');

$request = Request::capture();
$router  = new Router();

// ------------------------------------------------------------------
// Gate de instalación: si la app aún no fue instalada, todo el tráfico
// se enruta al asistente web (salvo los propios endpoints del instalador).
// ------------------------------------------------------------------
if (!Installer::isInstalled()) {
    $router->get('/install', [InstallController::class, 'show']);
    $router->post('/install', [InstallController::class, 'run']);

    if ($request->uri() !== '/install') {
        (Response::redirect('/install'))->send();
        return;
    }
} else {
    Session::start();
    // Registro central de rutas (panel + público OB + API).
    require BASE_PATH . '/src/Admin/routes.php';
}

// ------------------------------------------------------------------
// Despacho con manejo global de errores
// ------------------------------------------------------------------
try {
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    Logger::app('error', sprintf(
        '%s en %s:%d — %s',
        $e::class,
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    ));

    $debug = config('app.env') === 'development' && config('app.debug');
    $body  = $debug
        ? '<h1>500 — Error interno</h1><pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>'
        : '<h1>500 — Error interno del servidor</h1>';

    $response = Response::html($body, 500);
}

$response->send();
