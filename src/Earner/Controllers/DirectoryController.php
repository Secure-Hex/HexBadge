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
        $query = trim((string) $request->query('q', ''));

        // Dos consumidores para la misma ruta: el autocompletar la pide por
        // fetch y espera solo el fragmento del desplegable; una persona que
        // llega por la URL espera una página. Antes recibía el fragmento
        // crudo, sin estilos ni navegación.
        if ($request->header('X-Requested-With') === 'fetch') {
            return Response::html(View::renderPartial('search_results', [
                'query'   => $query,
                'results' => $query !== '' ? Earner::searchPublic($query, 8) : [],
            ]));
        }

        return $this->view('search_page', [
            'pageTitle' => $query !== '' ? 'Resultados para ' . $query : 'Buscar personas',
            'query'     => $query,
            'results'   => $query !== '' ? Earner::searchPublic($query, 40) : [],
        ]);
    }
}
