<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\Response;
use HexBadge\Core\View;

/**
 * Base de los controladores del portal earner. Renderiza con el layout
 * propio del earner (no comparte la nav del admin).
 */
abstract class EarnerBaseController
{
    /**
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], int $status = 200): Response
    {
        $data += ['appName' => config('app.name')];
        return View::render($view, $data, $status);
    }
}
