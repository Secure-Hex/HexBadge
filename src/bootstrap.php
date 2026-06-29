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

if (config('app.env') === 'development' && config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php_errors.log');
