<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Abstracción inmutable de la petición HTTP.
 *
 * Los controladores NUNCA deben tocar $_GET/$_POST/$_FILES/$_SERVER
 * directamente (CLAUDE.md §14); siempre a través de esta clase.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $get;
    /** @var array<string,mixed> */
    private array $post;
    /** @var array<string,mixed> */
    private array $files;
    /** @var array<string,mixed> */
    private array $server;
    /** Cuerpo crudo inyectado (tests); null = leer php://input. */
    private ?string $body;

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $server
     */
    public function __construct(array $get, array $post, array $files, array $server, ?string $body = null)
    {
        $this->body   = $body;
        $this->get    = $get;
        $this->post   = $post;
        $this->files  = $files;
        $this->server = $server;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_FILES, $_SERVER);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * Ruta normalizada sin query string ni slash final (excepto raíz).
     */
    public function uri(): string
    {
        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rawurldecode($path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Valor de query string ($_GET).
     */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->get[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Valor de cuerpo POST. Devuelve null si no es string escalar.
     */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Devuelve todo el cuerpo POST.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->post;
    }

    /**
     * Archivo subido por nombre de campo.
     *
     * @return array<string,mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    /**
     * Cuerpo crudo decodificado como JSON (para la API REST).
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $raw = $this->body();
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Cuerpo crudo de la petición, opcionalmente truncado a $maxBytes.
     */
    public function body(?int $maxBytes = null): string
    {
        $raw = $this->body ?? file_get_contents('php://input', false, null, 0, $maxBytes);
        return is_string($raw) ? $raw : '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        // Algunos headers (notablemente Authorization) no llegan a $_SERVER
        // bajo ciertas configuraciones de Apache + mod_php. Consultar los
        // headers reales de la petición como respaldo.
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $hName => $hValue) {
                if (strcasecmp($hName, $name) === 0) {
                    return is_string($hValue) ? $hValue : null;
                }
            }
        }

        // Variante con prefijo REDIRECT_ (cuando se reescribe vía .htaccess).
        $redirect = $this->server['REDIRECT_HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        return is_string($redirect) ? $redirect : null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * IP del cliente. No confiamos en cabeceras de proxy por defecto
     * (X-Forwarded-For es spoofeable); usamos REMOTE_ADDR.
     */
    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }

    public function userAgent(): ?string
    {
        $ua = $this->server['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) ? substr($ua, 0, 500) : null;
    }
}
