<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Router HTTP minimalista con soporte de parámetros nombrados {uuid}.
 *
 * Los handlers son [ControllerClass::class, 'method']. El controlador se
 * instancia sin argumentos; obtiene sus dependencias vía singletons.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,params:array<int,string>,handler:array{0:class-string,1:string}}> */
    private array $routes = [];

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    private function add(string $method, string $path, array $handler): void
    {
        // Extraer nombres de parámetros {nombre}.
        preg_match_all('/\{([a-z_]+)\}/', $path, $names);

        // Construir patrón regex. El placeholder captura cualquier segmento
        // sin barra (incluye emails y uuids); el controlador valida el valor.
        $pattern = preg_replace('/\{[a-z_]+\}/', '([^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'params'  => $names[1],
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri    = $request->uri();

        // Permitir override de método vía _method en formularios (DELETE).
        if ($method === 'POST') {
            $override = strtoupper((string) $request->input('_method', ''));
            if (in_array($override, ['DELETE', 'PUT', 'PATCH'], true)) {
                $method = $override;
            }
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $uri, $matches)) {
                array_shift($matches);
                [$controllerClass, $action] = $route['handler'];
                $controller = new $controllerClass();
                /** @var Response $response */
                $response = $controller->$action($request, ...$matches);
                return $response;
            }
        }

        return Response::html('<h1>404 — Página no encontrada</h1>', 404);
    }
}
