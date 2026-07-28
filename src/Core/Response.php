<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Respuesta HTTP. Centraliza los headers de seguridad (CLAUDE.md §4.7)
 * que se envían en TODA respuesta.
 */
final class Response
{
    private string $body;
    private int $status;
    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return new self($body === false ? '{}' : $body, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Página 404 con la identidad del sitio y una salida. Un <h1> suelto sin
     * estilos ni enlace deja a la persona sin nada que hacer.
     *
     * Es autónoma a propósito: los layouts necesitan sesión y contexto de vista
     * que en un 404 pueden no existir.
     */
    public static function notFound(string $what = 'No encontramos lo que buscabas.'): self
    {
        $body = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>No encontrado</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body>'
            . '<main class="auth-wrap"><div class="auth-card" style="text-align:center">'
            . '<h1 style="font-size:2.6rem;margin-bottom:.25rem">404</h1>'
            . '<p class="muted">' . e($what) . '</p>'
            . '<a class="btn btn-primary" href="/">Volver al inicio</a>'
            . '</div></main></body></html>';

        return self::html($body, 404);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Headers de seguridad obligatorios en todas las respuestas.
     */
    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Las imágenes de badges se sirven desde el dominio público (el de las
        // personas), distinto del dominio admin; se permite explícitamente.
        $publicOrigin = rtrim((string) config('app.earner_url'), '/');
        $imgSrc = "'self' data:" . ($publicOrigin !== '' ? ' ' . $publicOrigin : '');
        header(
            "Content-Security-Policy: default-src 'self'; img-src {$imgSrc}; "
            . "style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'"
        );

        if (config('app.env') === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Ocultar versión de PHP/servidor cuando sea posible.
        header_remove('X-Powered-By');
    }

    /**
     * Emite la respuesta al cliente.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            $this->securityHeaders();
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
