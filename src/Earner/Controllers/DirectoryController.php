<?php

declare(strict_types=1);

namespace HexBadge\Earner\Controllers;

use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\View;
use HexBadge\Models\Earner;

/**
 * Búsqueda de personas. Devuelve un fragmento HTML (sin layout) para el
 * autocompletar que vive en la cabecera de la wallet pública.
 */
final class DirectoryController extends EarnerBaseController
{
    public function search(Request $request): Response
    {
        $query   = trim((string) $request->query('q', ''));
        $results = $query !== '' ? Earner::searchPublic($query, 8) : [];

        return Response::html(View::renderPartial('search_results', [
            'query'   => $query,
            'results' => $results,
        ]));
    }
}
