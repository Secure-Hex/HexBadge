<?php

declare(strict_types=1);

namespace HexBadge\Services;

use RuntimeException;

/**
 * Cliente SMTP nativo (PHP puro, sin dependencias).
 *
 * Soporta:
 *  - encryption 'ssl'  -> TLS implícito (puerto 465)
 *  - encryption 'tls'  -> STARTTLS (puerto 587)
 *  - encryption 'none' -> sin cifrar (ej. catcher de dev como Mailpit:1025)
 *  - AUTH LOGIN cuando se proveen usuario/contraseña.
 */
final class SmtpMailer
{
    private $socket = null;
    private int $timeout = 15;

    /**
     * @param array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string} $cfg
     * @throws RuntimeException con un mensaje de diagnóstico.
     */
    public function send(array $cfg, string $toEmail, string $subject, string $htmlBody): void
    {
        $this->open($cfg);
        try {
            $this->deliver($cfg, $toEmail, $subject, $htmlBody);
        } finally {
            $this->close();
        }
    }

    /**
     * Abre la conexión SMTP (saludo + STARTTLS + AUTH). Para envío por lotes:
     * abrir una vez, llamar deliver() varias veces y cerrar al final.
     *
     * @param array<string,mixed> $cfg
     */
    public function open(array $cfg): void
    {
        $transport = $cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0; $errstr = '';

        $this->socket = @stream_socket_client(
            $transport . $cfg['host'] . ':' . $cfg['port'],
            $errno,
            $errstr,
            $this->timeout
        );
        if ($this->socket === false) {
            throw new RuntimeException("No se pudo conectar a {$cfg['host']}:{$cfg['port']} ({$errstr})");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $this->expect('220');
        $ehloHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $this->command('EHLO ' . $ehloHost, '250');

        if ($cfg['encryption'] === 'tls') {
            $this->command('STARTTLS', '220');
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Fallo al iniciar STARTTLS');
            }
            $this->command('EHLO ' . $ehloHost, '250');
        }

        if ($cfg['username'] !== '') {
            $this->command('AUTH LOGIN', '334');
            $this->command(base64_encode($cfg['username']), '334');
            $this->command(base64_encode($cfg['password']), '235');
        }
    }

    /**
     * Envía un mensaje sobre una conexión ya abierta (reutilizable en lote).
     *
     * @param array<string,mixed> $cfg
     */
    public function deliver(array $cfg, string $toEmail, string $subject, string $htmlBody): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Conexión SMTP no abierta');
        }
        // RSET limpia el estado por si quedó algo de un envío anterior.
        $this->command('RSET', '250');
        $this->command('MAIL FROM:<' . $cfg['from_address'] . '>', '250');
        $this->command('RCPT TO:<' . $toEmail . '>', '250');
        $this->command('DATA', '354');
        fwrite($this->socket, $this->buildMessage($cfg, $toEmail, $subject, $htmlBody) . "\r\n.\r\n");
        $this->expect('250');
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            try { $this->command('QUIT', '221'); } catch (\Throwable) { /* ignorar */ }
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
        $this->socket = null;
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function buildMessage(array $cfg, string $to, string $subject, string $html): string
    {
        $from   = $cfg['from_address'];
        $name   = '=?UTF-8?B?' . base64_encode((string) $cfg['from_name']) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $name . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            // Message-ID con el dominio del remitente: muchos filtros (Gmail)
            // penalizan o descartan correos sin este header.
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->fromDomain($from) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // Cuerpo en base64 (evita problemas de líneas largas / dot-stuffing).
        $body = chunk_split(base64_encode($html));
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function fromDomain(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? 'localhost' : substr($email, $at + 1);
    }

    private function command(string $cmd, string $expectedCode): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Conexión SMTP cerrada');
        }
        fwrite($this->socket, $cmd . "\r\n");
        $this->expect($expectedCode);
    }

    private function expect(string $code): void
    {
        $response = $this->readResponse();
        if (!str_starts_with($response, $code)) {
            throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($response));
        }
    }

    private function readResponse(): string
    {
        $data = '';
        while (is_resource($this->socket) && ($line = fgets($this->socket, 515)) !== false) {
            $data .= $line;
            // Última línea de una respuesta multilínea: "250 texto" (espacio en pos 4).
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($data === '') {
            throw new RuntimeException('Sin respuesta del servidor SMTP (timeout)');
        }
        return $data;
    }
}
