<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\Request;
use HexBadge\Core\Response;

/**
 * Home del portal earner.
 */
final class HomeController extends EarnerBaseController
{
    public function index(Request $request): Response
    {
        return $this->view('home', ['pageTitle' => 'Inicio']);
    }
}
