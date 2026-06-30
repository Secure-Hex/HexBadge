<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Core\Logger;

/**
 * Recuperación de contraseña para el panel admin (users) y el portal earner
 * (earners). Token de un solo uso, hasheado en BD (SHA-256), expira en 1 hora.
 *
 * El flujo no revela si un email existe (anti-enumeración): el controlador
 * siempre responde igual; este servicio simplemente no hace nada si la cuenta
 * no existe o no tiene contraseña.
 */
final class PasswordResetService
{
    private const TTL_SECONDS = 3600; // 1 hora

    /**
     * Configuración por audiencia. `table` proviene de este whitelist (no de
     * input del usuario), por eso se interpola en las queries sin riesgo.
     *
     * @var array<string,array{table:string,nameField:string,urlKey:string,withCompany:bool,activeOnly:bool,subject:string}>
     */
    private const AUDIENCES = [
        'admin' => [
            'table'       => 'users',
            'nameField'   => 'name',
            'urlKey'      => 'app.url',
            'withCompany' => true,   // usa el SMTP de la empresa del usuario si tiene
            'activeOnly'  => true,   // no resetear cuentas desactivadas
            'subject'     => 'Restablecé tu contraseña',
        ],
        'earner' => [
            'table'       => 'earners',
            'nameField'   => 'display_name',
            'urlKey'      => 'app.earner_url',
            'withCompany' => false,
            'activeOnly'  => false,
            'subject'     => 'Restablecé tu contraseña',
        ],
    ];

    /**
     * Genera un token y envía el email si la cuenta existe y tiene contraseña.
     * No revela el resultado (siempre silencioso desde fuera).
     */
    public function request(string $audience, string $email): void
    {
        $cfg   = self::AUDIENCES[$audience];
        $db    = Database::getInstance();
        $email = strtolower(trim($email));

        $sql  = "SELECT * FROM {$cfg['table']} WHERE email = ?";
        $sql .= $cfg['activeOnly'] ? ' AND is_active = 1' : '';
        $row  = $db->fetchOne($sql . ' LIMIT 1', [$email]);

        // Sin cuenta, o cuenta sin contraseña (earner que nunca se registró):
        // no se puede restablecer. Salimos en silencio.
        if ($row === null || empty($row['password_hash'])) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $db->update($cfg['table'], [
            'reset_token_hash' => hash('sha256', $rawToken),
            'reset_expires'    => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ], 'id = ?', [(int) $row['id']]);

        $companyId = ($cfg['withCompany'] && $row['company_id'] !== null) ? (int) $row['company_id'] : null;
        $this->sendEmail($audience, (string) $row['email'], (string) ($row[$cfg['nameField']] ?? ''), $rawToken, $companyId);
        Logger::audit('password.reset.requested', null, $audience === 'admin' ? 'user' : 'earner', (string) ($row['uuid'] ?? ''), ['email' => $email]);
    }

    /**
     * Devuelve la fila si el token es válido (existe y no expiró); si no, null.
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $audience, string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }
        $cfg = self::AUDIENCES[$audience];
        return Database::getInstance()->fetchOne(
            "SELECT * FROM {$cfg['table']} WHERE reset_token_hash = ? AND reset_expires > NOW() LIMIT 1",
            [hash('sha256', $rawToken)]
        );
    }

    /**
     * Aplica la nueva contraseña y consume el token. Devuelve false si el token
     * ya no es válido.
     */
    public function reset(string $audience, string $rawToken, string $password, ?string $ip = null): bool
    {
        $row = $this->findValid($audience, $rawToken);
        if ($row === null) {
            return false;
        }
        $cfg = self::AUDIENCES[$audience];
        Database::getInstance()->update($cfg['table'], [
            'password_hash'    => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'reset_token_hash' => null,
            'reset_expires'    => null,
        ], 'id = ?', [(int) $row['id']]);
        Logger::audit('password.reset.completed', null, $audience === 'admin' ? 'user' : 'earner', (string) ($row['uuid'] ?? ''));
        self::notifyChanged($audience, $row, $ip);
        return true;
    }

    /**
     * Envía un aviso de seguridad de que la contraseña fue cambiada, con un
     * enlace para restablecerla si el cambio no fue reconocido. Tolerante a
     * fallos: un error de correo no interrumpe la operación ya realizada.
     *
     * @param array<string,mixed> $account Fila de la cuenta (email, nombre, company_id).
     */
    public static function notifyChanged(string $audience, array $account, ?string $ip = null): void
    {
        $cfg   = self::AUDIENCES[$audience];
        $email = (string) ($account['email'] ?? '');
        if ($email === '') {
            return;
        }
        $name      = (string) ($account[$cfg['nameField']] ?? '');
        $companyId = ($cfg['withCompany'] && ($account['company_id'] ?? null) !== null) ? (int) $account['company_id'] : null;
        $forgotUrl = rtrim((string) config($cfg['urlKey']), '/') . '/forgot-password';
        $subject   = 'Tu contraseña fue cambiada';
        $hi        = $name !== '' ? 'Hola ' . e($name) . ',' : 'Hola,';
        $when      = date('d/m/Y H:i') . ' (hora del servidor)';
        $detail    = 'la contraseña de tu cuenta (<strong>' . e($email) . '</strong>) se cambió el ' . e($when) . '.';
        if ($ip !== null && $ip !== '') {
            $detail .= ' Origen: ' . e($ip) . '.';
        }

        $inner = EmailTemplate::heading('Tu contraseña fue cambiada')
               . '<p style="text-align:center;margin:0 0 4px">' . $hi . '</p>'
               . '<p style="text-align:center;color:#697587;margin:6px 0 0">Te avisamos que ' . $detail . '</p>'
               . '<p style="text-align:center;color:#697587;margin:10px 0 0">Si fuiste vos, no tenés que hacer nada. Si no reconocés este cambio, restablecé tu contraseña de inmediato:</p>'
               . EmailTemplate::button('Restablecer contraseña', $forgotUrl);

        try {
            (new EmailService())->send($email, $subject, EmailTemplate::wrap($inner, $subject), $companyId);
        } catch (\Throwable $e) {
            Logger::app('warning', 'No se pudo enviar aviso de cambio de contraseña: ' . $e->getMessage());
        }
    }

    private function sendEmail(string $audience, string $email, string $name, string $rawToken, ?int $companyId): void
    {
        $cfg = self::AUDIENCES[$audience];
        $url = rtrim((string) config($cfg['urlKey']), '/') . '/reset-password/' . $rawToken;
        $hi  = $name !== '' ? 'Hola ' . e($name) . ',' : 'Hola,';

        $inner = EmailTemplate::heading('Restablecé tu contraseña')
               . '<p style="text-align:center;margin:0 0 4px">' . $hi . '</p>'
               . '<p style="text-align:center;color:#697587;margin:6px 0 0">Recibimos un pedido para restablecer la contraseña de tu cuenta. Hacé clic para elegir una nueva.</p>'
               . EmailTemplate::button('Elegir nueva contraseña', $url)
               . EmailTemplate::muted('El enlace vence en 1 hora. Si no lo pediste, ignorá este correo: tu contraseña no cambia.');

        $html = EmailTemplate::wrap($inner, $cfg['subject']);
        (new EmailService())->send($email, $cfg['subject'], $html, $companyId);
    }
}
