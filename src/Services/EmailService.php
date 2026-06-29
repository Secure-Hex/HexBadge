<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Crypto;
use HexBadge\Core\Logger;
use HexBadge\Models\Company;

/**
 * Envío de notificaciones por email.
 *
 * SMTP en cascada: si el correo es de una empresa con SMTP propio, usa ese;
 * si no, cae al SMTP global de la plataforma (tabla settings); y si tampoco
 * hay, "entrega" a archivos HTML en storage/mail/ (modo dev).
 */
final class EmailService
{
    private const SPOOL_DIR = BASE_PATH . '/storage/mail/';

    /**
     * Envía un correo HTML. Devuelve true si se entregó/encoló.
     */
    public function send(string $toEmail, string $subject, string $htmlBody, ?int $companyId = null): bool
    {
        $cfg = $this->resolveConfig($companyId);

        if ($cfg === null) {
            // Sin SMTP configurado: spool a archivo (modo dev).
            return $this->spool($toEmail, $subject, $htmlBody, $cfg);
        }

        try {
            (new SmtpMailer())->send($cfg, $toEmail, $subject, $htmlBody);
            Logger::app('info', 'Email enviado vía SMTP a ' . $toEmail . ' (' . $subject . ')');
            return true;
        } catch (\Throwable $e) {
            Logger::app('error', 'Fallo SMTP a ' . $toEmail . ': ' . $e->getMessage());
            // Respaldo: spool para no perder el contenido.
            $this->spool($toEmail, $subject, $htmlBody, $cfg);
            return false;
        }
    }

    /**
     * Envía muchos correos reutilizando UNA sola conexión SMTP (para emisión
     * masiva). Mucho más rápido y evita timeouts con listas grandes.
     *
     * @param array<int,array{to:string,subject:string,html:string}> $messages
     * @return array<int,bool> Éxito por índice de mensaje.
     */
    public function sendMany(array $messages, ?int $companyId = null): array
    {
        $results = [];
        $cfg = $this->resolveConfig($companyId);

        // Sin SMTP: spool de cada uno (modo dev).
        if ($cfg === null) {
            foreach ($messages as $i => $m) {
                $results[$i] = $this->spool($m['to'], $m['subject'], $m['html'], null);
            }
            return $results;
        }

        $mailer = new SmtpMailer();
        try {
            $mailer->open($cfg);
            foreach ($messages as $i => $m) {
                try {
                    $mailer->deliver($cfg, $m['to'], $m['subject'], $m['html']);
                    $results[$i] = true;
                } catch (\Throwable $e) {
                    Logger::app('error', 'Fallo SMTP (lote) a ' . $m['to'] . ': ' . $e->getMessage());
                    $results[$i] = false;
                }
            }
        } catch (\Throwable $e) {
            // No se pudo abrir la conexión: spool de los que falten.
            Logger::app('error', 'Fallo al abrir SMTP (lote): ' . $e->getMessage());
            foreach ($messages as $i => $m) {
                if (!isset($results[$i])) {
                    $results[$i] = $this->spool($m['to'], $m['subject'], $m['html'], $cfg);
                }
            }
        } finally {
            $mailer->close();
        }

        $sent = count(array_filter($results));
        Logger::app('info', "Email lote: {$sent}/" . count($messages) . ' enviados vía SMTP.');
        return $results;
    }

    /**
     * Envía un correo de prueba; lanza la excepción real para mostrarla en
     * la pantalla de configuración.
     *
     * @throws \Throwable
     */
    public function sendTest(string $toEmail, ?int $companyId = null): void
    {
        $cfg = $this->resolveConfig($companyId);
        if ($cfg === null) {
            throw new \RuntimeException('SMTP no está configurado.');
        }
        $inner = EmailTemplate::heading('Prueba de correo')
               . '<p style="text-align:center;margin:0">Si estás viendo este correo, la configuración SMTP de '
               . '<strong>HexBadge</strong> funciona correctamente. ✅</p>';
        $body  = EmailTemplate::wrap($inner, 'Prueba de SMTP de HexBadge');
        (new SmtpMailer())->send($cfg, $toEmail, 'Prueba SMTP — HexBadge', $body);
    }

    /**
     * Devuelve la config SMTP efectiva o null si no hay host.
     *
     * @return array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string}|null
     */
    private function resolveConfig(?int $companyId = null): ?array
    {
        // 0) SMTP propio de la empresa (override): tiene prioridad sobre todo.
        $companyCfg = $this->companyConfig($companyId);
        if ($companyCfg !== null) {
            return $companyCfg;
        }

        // 1) Ajustes de BD (editables en el panel).
        $host = SettingsService::get('smtp_host');

        if ($host !== '') {
            return [
                'host'         => $host,
                'port'         => (int) (SettingsService::get('smtp_port', '587') ?: '587'),
                'username'     => SettingsService::get('smtp_username'),
                'password'     => SettingsService::get('smtp_password'),
                'encryption'   => SettingsService::get('smtp_encryption', 'tls') ?: 'tls',
                'from_address' => SettingsService::get('smtp_from_address') ?: (string) config('mail.from_address'),
                'from_name'    => SettingsService::get('smtp_from_name') ?: (string) config('mail.from_name', 'HexBadge'),
            ];
        }

        // 2) Fallback a .env (si se configuró ahí y no es el placeholder).
        $envHost = (string) config('mail.host', '');
        if ($envHost !== '' && $envHost !== 'smtp.example.com') {
            return [
                'host'         => $envHost,
                'port'         => (int) config('mail.port', 587),
                'username'     => (string) config('mail.username', ''),
                'password'     => (string) config('mail.password', ''),
                'encryption'   => (string) config('mail.encryption', 'tls'),
                'from_address' => (string) config('mail.from_address', 'noreply@localhost'),
                'from_name'    => (string) config('mail.from_name', 'HexBadge'),
            ];
        }

        return null;
    }

    /**
     * Config SMTP propia de una empresa, o null si no tiene (usa el global).
     *
     * @return array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string}|null
     */
    private function companyConfig(?int $companyId): ?array
    {
        if ($companyId === null) {
            return null;
        }
        $c = Company::find($companyId);
        if ($c === null || trim((string) ($c['smtp_host'] ?? '')) === '') {
            return null;
        }
        $pass = (string) ($c['smtp_password'] ?? '');
        return [
            'host'         => (string) $c['smtp_host'],
            'port'         => (int) ($c['smtp_port'] ?: 587),
            'username'     => (string) ($c['smtp_username'] ?? ''),
            'password'     => $pass !== '' ? Crypto::decrypt($pass) : '',
            'encryption'   => (string) ($c['smtp_encryption'] ?: 'tls'),
            'from_address' => (string) ($c['smtp_from_address'] ?: ($c['issuer_email'] ?: config('mail.from_address'))),
            'from_name'    => (string) ($c['smtp_from_name'] ?: ($c['name'] ?: config('mail.from_name', 'HexBadge'))),
        ];
    }

    /**
     * @param array<string,mixed>|null $cfg
     */
    private function spool(string $to, string $subject, string $body, ?array $cfg): bool
    {
        if (!is_dir(self::SPOOL_DIR) && !mkdir(self::SPOOL_DIR, 0750, true) && !is_dir(self::SPOOL_DIR)) {
            Logger::app('error', 'No se pudo crear el directorio de spool de email');
            return false;
        }

        $fromAddr = $cfg['from_address'] ?? (string) config('mail.from_address', 'noreply@localhost');
        $fromName = $cfg['from_name'] ?? (string) config('mail.from_name', 'HexBadge');

        $safeTo = preg_replace('/[^a-zA-Z0-9._@-]/', '_', $to) ?? 'dest';
        $file   = self::SPOOL_DIR . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeTo . '.html';

        $meta = sprintf("<!-- To: %s | From: %s <%s> | Subject: %s | %s -->\n", $to, $fromName, $fromAddr, $subject, date('c'));
        file_put_contents($file, $meta . $body);
        @chmod($file, 0640);
        Logger::app('info', 'Email encolado en spool: ' . basename($file) . ' (asunto: ' . $subject . ')');
        return true;
    }
}
