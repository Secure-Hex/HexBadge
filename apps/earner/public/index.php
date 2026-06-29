<?php

/**
 * Front controller del PORTAL EARNER de HexBadge.
 *
 * Docroot: apps/earner/public/. Frontend separado del panel admin; comparte
 * la misma base de datos y el core (src/). Aquí los receptores aceptan sus
 * badges, arman su perfil y los comparten.
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

View::setBasePath(BASE_PATH . '/src/Earner/Views');

$request = Request::capture();

// Si la plataforma aún no fue instalada, el portal earner no opera todavía.
if (!Installer::isInstalled()) {
    (Response::html('<h1>HexBadge aún no está configurado.</h1>', 503))->send();
    return;
}

// Sesión del earner con cookie propia (independiente del panel admin).
Session::start('HEXBADGE_EARNER');

$router = new Router();
require BASE_PATH . '/src/Earner/routes.php';

try {
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    Logger::app('error', sprintf('[earner] %s en %s:%d — %s', $e::class, $e->getFile(), $e->getLine(), $e->getMessage()));
    $response = Response::html('<h1>500 — Error interno</h1>', 500);
}

$response->send();
