<?php

/**
 * Bootstrap de HexBadge.
 *
 * Carga el entorno (.env), define helpers globales, registra un autoloader
 * PSR-4 para el namespace HexBadge\ y configura el manejo de errores.
 *
 * Se incluye una sola vez desde el front controller (public/index.php) y
 * desde los scripts CLI (scripts/*.php).
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// ------------------------------------------------------------------
// Carga de variables de entorno desde .env (parser minimalista propio)
// ------------------------------------------------------------------

/**
 * Lee el archivo .env una sola vez y lo cachea en una variable estática.
 * No usamos putenv/getenv para evitar fugas a procesos hijos; guardamos
 * los valores en un registro interno consultado por env().
 *
 * @return array<string,string>
 */
function load_env(): array
{
    static $vars = null;
    if ($vars !== null) {
        return $vars;
    }

    $vars = [];
    $path = BASE_PATH . '/.env';
    if (!is_readable($path)) {
        return $vars;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Eliminar comentario inline solo si el valor no está entre comillas.
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $hashPos = strpos($value, ' #');
            if ($hashPos !== false) {
                $value = rtrim(substr($value, 0, $hashPos));
            }
        }

        // Quitar comillas envolventes.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $vars[$key] = $value;
    }

    return $vars;
}

/**
 * Obtiene una variable de entorno con valor por defecto opcional.
 */
function env(string $key, ?string $default = null): ?string
{
    $vars = load_env();
    return array_key_exists($key, $vars) ? $vars[$key] : $default;
}

/**
 * Acceso a configuración con notación de punto: config('app.url').
 *
 * @return mixed
 */
function config(string $key, mixed $default = null): mixed
{
    static $cache = [];

    $segments = explode('.', $key);
    $file     = array_shift($segments);

    if (!array_key_exists($file, $cache)) {
        $path = BASE_PATH . '/config/' . $file . '.php';
        $cache[$file] = is_readable($path) ? require $path : [];
    }

    $value = $cache[$file];
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

/**
 * Escapa una cadena para renderizado seguro en HTML (anti-XSS).
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Base URL pública (dominio de las personas): verificación, imágenes de
 * badges y Open Badges. Distinta del dominio de administración.
 */
function public_url(string $path = ''): string
{
    $base = rtrim((string) config('app.earner_url'), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/**
 * URL pública de la imagen de un badge.
 */
function badge_image_url(?string $filename): string
{
    return public_url('uploads/badges/' . basename((string) $filename));
}

/**
 * URL pública de una imagen de perfil (avatar o portada).
 */
function profile_image_url(?string $filename): string
{
    return public_url('uploads/profiles/' . basename((string) $filename));
}

/**
 * URL pública del logo de una empresa.
 */
function logo_image_url(?string $filename): string
{
    return public_url('uploads/logos/' . basename((string) $filename));
}

/**
 * Definición de las redes/enlaces del perfil del receptor: columna en
 * `earners`, etiqueta, color de marca y path SVG (simple-icons, viewBox 24).
 * Compartida por el formulario de edición y la wallet pública.
 *
 * @return array<int,array{key:string,label:string,brand:string,icon:string}>
 */
function social_networks(): array
{
    return [
        ['key' => 'linkedin_url',  'label' => 'LinkedIn',  'brand' => '#0A66C2', 'icon' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'],
        ['key' => 'x_url',         'label' => 'X',         'brand' => '#000000', 'icon' => 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z'],
        ['key' => 'instagram_url', 'label' => 'Instagram', 'brand' => '#E4405F', 'icon' => 'M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z'],
        ['key' => 'github_url',    'label' => 'GitHub',    'brand' => '#181717', 'icon' => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12'],
        ['key' => 'profile_url',   'label' => 'Sitio web', 'brand' => '#2456d6', 'icon' => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'],
    ];
}

/**
 * Genera un UUID v4 criptográficamente seguro.
 */
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versión 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variante RFC 4122

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ------------------------------------------------------------------
// Autoloader PSR-4 para HexBadge\  ->  src/
// ------------------------------------------------------------------

spl_autoload_register(static function (string $class): void {
    $prefix  = 'HexBadge\\';
    $baseDir = BASE_PATH . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

// ------------------------------------------------------------------
// Manejo de errores según entorno
// ------------------------------------------------------------------

$appDebug = config('app.env') === 'development' && (bool) config('app.debug');

if ($appDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php_errors.log');

/**
 * Logea el error y muestra una respuesta 500 en vez de una pantalla en blanco.
 * En debug imprime el detalle; en producción, una página genérica con un id de
 * correlación (el mensaje real solo queda en storage/logs/app.log).
 */
function hexbadge_render_error(\Throwable $e, bool $debug): void
{
    $ref = bin2hex(random_bytes(4));
    \HexBadge\Core\Logger::app('error', sprintf(
        '[%s] %s: %s en %s:%d',
        $ref,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (headers_sent()) {
        return; // La salida ya empezó: no podemos reescribir el status/cuerpo.
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    if ($debug) {
        echo '<pre style="padding:1rem;font:14px/1.5 monospace;white-space:pre-wrap">'
            . e((string) $e) . '</pre>';
        return;
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Error del servidor</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#f6f7f9;color:#1f2430;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
        . '.box{max-width:28rem;padding:2rem;text-align:center}h1{font-size:3rem;margin:0}'
        . 'p{color:#5b6472}code{font-size:.85em;color:#8a93a2}</style></head><body>'
        . '<div class="box"><h1>500</h1><p>Ocurrió un error inesperado. '
        . 'Ya quedó registrado y lo estamos revisando.</p>'
        . '<p><code>Ref: ' . e($ref) . '</code></p></div></body></html>';
}

set_exception_handler(static function (\Throwable $e) use ($appDebug): void {
    hexbadge_render_error($e, $appDebug);
});

// Fatals (E_ERROR, parse, memoria agotada) no pasan por el exception handler.
register_shutdown_function(static function () use ($appDebug): void {
    $err = error_get_last();
    if ($err === null
        || ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) === 0) {
        return;
    }
    hexbadge_render_error(
        new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']),
        $appDebug
    );
});

/**
 * Ruta de un asset estático con cache-busting por mtime (?v=…). Evita servir
 * CSS/JS cacheado viejo tras un deploy. Devuelve la ruta sin versión si el
 * archivo no existe (p. ej. en CLI, donde no hay DOCUMENT_ROOT).
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $rel  = '/assets/' . $path;
    $file = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/assets/' . $path;

    return is_file($file) ? $rel . '?v=' . filemtime($file) : $rel;
}
