<?php

declare(strict_types=1);

namespace HexBadge\Core;

use RuntimeException;

/**
 * Renderizador de vistas PHP planas.
 *
 * Las variables se pasan como array asociativo y se exponen como variables
 * locales en la vista (nunca se usa extract() con datos del usuario §14:
 * aquí los datos provienen del controlador, no del request crudo, y las
 * vistas escapan toda salida con e()).
 */
final class View
{
    /**
     * Directorio base de vistas. Cada app (admin/earner) lo fija en su
     * front controller con setBasePath(); por defecto apunta al admin.
     */
    private static string $baseDir = BASE_PATH . '/src/Admin/Views/';

    public static function setBasePath(string $dir): void
    {
        self::$baseDir = rtrim($dir, '/') . '/';
    }

    /**
     * Renderiza una vista dentro del layout principal.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], int $status = 200): Response
    {
        $content = self::renderPartial($view, $data);

        $layoutData = array_merge($data, ['content' => $content]);
        $html       = self::renderPartial('layout/base', $layoutData);

        return Response::html($html, $status);
    }

    /**
     * Renderiza una vista sin layout (parciales, fragmentos de email, etc.).
     *
     * @param array<string,mixed> $data
     */
    public static function renderPartial(string $view, array $data = []): string
    {
        $path = self::$baseDir . $view . '.php';
        if (!is_readable($path)) {
            throw new RuntimeException('Vista no encontrada: ' . $view);
        }

        // Aislar el scope de la vista en una closure para no exponer $this
        // ni el resto del entorno.
        $renderer = static function (string $__path, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__path;
            return (string) ob_get_clean();
        };

        return $renderer($path, $data);
    }
}
