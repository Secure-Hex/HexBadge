<?php

declare(strict_types=1);

namespace HexBadge\Core;

use HexBadge\Models\Company;

/**
 * Controlador base. Provee helpers comunes a todos los controladores.
 */
abstract class Controller
{
    private ?Database $dbInstance = null;

    /** Opciones permitidas de "entradas por pantalla". */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Conexión a BD perezosa: solo se abre cuando un controlador la usa.
     * Así las páginas que no tocan la BD (login, errores) renderizan aunque
     * la base de datos esté caída.
     */
    protected function db(): Database
    {
        return $this->dbInstance ??= Database::getInstance();
    }

    /**
     * Lee y valida el parámetro de paginación "per" (entradas por pantalla),
     * limitándolo a un conjunto fijo de opciones; por defecto 25.
     */
    protected function perPage(Request $request, int $default = 25): int
    {
        $per = (int) $request->query('per', (string) $default);
        return in_array($per, self::PER_PAGE_OPTIONS, true) ? $per : $default;
    }

    // ---- Multitenancy (aislamiento por empresa) ----

    /**
     * Resuelve la empresa por la que deben filtrarse los LISTADOS.
     * - Superadmin: lee ?company= (0/ausente = null = "todas las empresas").
     * - Sub-admin: forzado a su propia empresa (ignora ?company).
     * Devuelve null = sin filtro (solo posible para superadmin).
     */
    protected function companyFilter(Request $request): ?int
    {
        if (!Auth::isSuperadmin()) {
            // Sub-admin sin empresa = estado inválido: no mostrar nada.
            return Auth::companyId() ?? -1;
        }
        $c = (int) $request->query('company', '0');
        return $c > 0 ? $c : null;
    }

    /**
     * Empresa con la que se CREA/EMITE: superadmin la toma del request (o null
     * si no eligió); sub-admin siempre la suya.
     */
    protected function companyForWrite(Request $request): ?int
    {
        if (!Auth::isSuperadmin()) {
            return Auth::companyId();
        }
        $c = (int) $request->input('company_id', '0');
        return $c > 0 ? $c : null;
    }

    /**
     * Corta con 403 si un sub-admin intenta acceder a una entidad de otra
     * empresa. El superadmin siempre pasa.
     */
    protected function assertCompanyAccess(?int $entityCompanyId): ?Response
    {
        if (Auth::isSuperadmin()) {
            return null;
        }
        if ($entityCompanyId !== null && $entityCompanyId === Auth::companyId()) {
            return null;
        }
        return Response::html('<h1>403 — Acceso denegado</h1>', 403);
    }

    /**
     * Empresas disponibles para selectores/filtros según el rol.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function companiesForSelector(): array
    {
        if (Auth::isSuperadmin()) {
            return Company::allOrdered();
        }
        $cid = Auth::companyId();
        if ($cid === null) {
            return [];
        }
        $c = Company::find($cid);
        return $c !== null ? [$c] : [];
    }

    /**
     * ¿El usuario actual puede asignar/operar sobre esa empresa?
     */
    protected function isCompanyAllowed(int $companyId): bool
    {
        foreach ($this->companiesForSelector() as $c) {
            if ((int) $c['id'] === $companyId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Renderiza una vista con layout.
     *
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], int $status = 200): Response
    {
        // Datos disponibles en todas las vistas.
        $data += [
            'appName'     => config('app.name'),
            'currentUser' => Auth::check() ? [
                'id'   => Auth::id(),
                'role' => Auth::role(),
                'name' => Session::get('user_name'),
            ] : null,
        ];
        return View::render($view, $data, $status);
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $location): Response
    {
        return Response::redirect($location);
    }

    /**
     * Verifica CSRF en peticiones POST. Aborta con 419 si es inválido.
     */
    protected function verifyCsrf(Request $request): void
    {
        CSRF::check($request);
    }
}
