<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;
use HexBadge\Services\CertificateService;
use RuntimeException;

/**
 * Lógica de negocio de emisión de badges (CLAUDE.md §6.2).
 *
 * Reutilizada por emisión individual, masiva (CSV) y API.
 */
final class BadgeService
{
    private Database $db;
    private OpenBadgeService $ob;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ob = new OpenBadgeService();
    }

    /**
     * Emite un badge. Devuelve un resultado estructurado en lugar de lanzar
     * para que la emisión masiva pueda continuar ante filas con problemas.
     *
     * @return array{ok:bool, reason:?string, badge_uuid:?string, accept_token:?string, earner_id:?int}
     */
    public function issue(
        string $templateUuid,
        string $email,
        string $firstName,
        string $lastName,
        int $issuedBy,
        string $via = 'manual',
        string $locale = 'es'
    ): array {
        $template = BadgeTemplate::findByUuid($templateUuid);
        if ($template === null || $template['state'] !== 'active' || (int) $template['is_active'] !== 1) {
            return $this->fail('template_not_found');
        }

        $earner = Earner::findOrCreate($email, $firstName, $lastName);

        if (IssuedBadge::hasActiveDuplicate((int) $template['id'], (int) $earner['id'])) {
            return $this->fail('duplicate', null, null, (int) $earner['id']);
        }

        $uuid        = uuid4();
        $now         = date('Y-m-d H:i:s');
        $expiresAt   = null;
        if (!empty($template['expires_days'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + ((int) $template['expires_days'] * 86400));
        }

        // Token de aceptación: se envía el crudo por email, se guarda el hash.
        $rawToken    = bin2hex(random_bytes(32));
        $tokenHash   = hash('sha256', $rawToken);
        $tokenExpiry = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 días

        $this->db->beginTransaction();
        try {
            $id = IssuedBadge::create([
                'uuid'                 => $uuid,
                'badge_template_id'    => (int) $template['id'],
                'earner_id'            => (int) $earner['id'],
                'issued_by'            => $issuedBy,
                'issued_via'           => $via,
                'issued_at'            => $now,
                'expires_at'           => $expiresAt,
                'status'               => 'pending',
                'accept_token'         => $tokenHash,
                'accept_token_expires' => $tokenExpiry,
                'recipient_email'      => strtolower($email),
                'locale'               => $locale,
            ]);

            // Construir y cachear la assertion Open Badge.
            $full = IssuedBadge::findFullByUuid($uuid);
            if ($full !== null) {
                $assertion = $this->ob->buildAssertion($full);
                IssuedBadge::updateById($id, ['ob_assertion_json' => json_encode($assertion, JSON_UNESCAPED_UNICODE)]);
            }

            BadgeTemplate::incrementIssued((int) $template['id']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::app('error', 'Fallo al emitir badge: ' . $e->getMessage());
            throw new RuntimeException('No se pudo emitir el badge');
        }

        Logger::audit('badge.issued', $issuedBy, 'issued_badge', $uuid, [
            'template' => $template['uuid'],
            'via'      => $via,
        ]);

        return [
            'ok'           => true,
            'reason'       => null,
            'badge_uuid'   => $uuid,
            'accept_token' => $rawToken,
            'earner_id'    => (int) $earner['id'],
        ];
    }

    /**
     * Revoca un badge emitido.
     */
    public function revoke(string $uuid, string $reason, int $userId): bool
    {
        $badge = IssuedBadge::findByUuid($uuid);
        if ($badge === null || $badge['status'] === 'revoked') {
            return false;
        }

        IssuedBadge::updateById((int) $badge['id'], [
            'status'        => 'revoked',
            'revoked_at'    => date('Y-m-d H:i:s'),
            'revoke_reason' => mb_substr($reason, 0, 500),
        ]);

        // Actualizar la assertion cacheada para reflejar la revocación.
        $full = IssuedBadge::findFullByUuid($uuid);
        if ($full !== null) {
            $assertion = $this->ob->buildAssertion($full);
            IssuedBadge::updateById((int) $badge['id'], ['ob_assertion_json' => json_encode($assertion, JSON_UNESCAPED_UNICODE)]);
        }

        Logger::audit('badge.revoked', $userId, 'issued_badge', $uuid, ['reason' => $reason]);
        return true;
    }

    /**
     * Envía (o reenvía) el correo de notificación de un badge — el mismo de la
     * emisión individual. Lo usan la emisión individual, la masiva y el reenvío.
     *
     * @param string|null $rawToken Token de aceptación en claro. Si es null
     *   (reenvío), se genera uno nuevo y se invalida el anterior.
     */
    public function sendNotification(string $badgeUuid, ?string $rawToken = null): bool
    {
        $msg = $this->buildNotificationMessage($badgeUuid, $rawToken);
        if ($msg === null) {
            return false;
        }
        $ok = (new EmailService())->send($msg['to'], $msg['subject'], $msg['html'], $msg['company_id'] ?? null);
        if ($ok) {
            $this->markNotificationSent($msg['badge_id']);
        }
        return $ok;
    }

    /**
     * Arma el mensaje de notificación (sin enviarlo) para envío por lotes.
     * Si $rawToken es null, regenera el token de aceptación.
     *
     * @return array{badge_id:int,company_id:?int,to:string,subject:string,html:string}|null
     */
    public function buildNotificationMessage(string $badgeUuid, ?string $rawToken = null): ?array
    {
        $badge = IssuedBadge::findFullByUuid($badgeUuid);
        if ($badge === null || $badge['status'] === 'revoked') {
            return null;
        }

        if ($rawToken === null) {
            $rawToken = bin2hex(random_bytes(32));
            IssuedBadge::updateById((int) $badge['id'], [
                'accept_token'         => hash('sha256', $rawToken),
                'accept_token_expires' => date('Y-m-d H:i:s', time() + (30 * 86400)),
            ]);
        }

        $badgeName  = (string) $badge['template_name'];
        $issuerName = (string) ($badge['issuer_name'] ?? 'SecureHex');
        $firstName  = (string) $badge['first_name'];
        $acceptUrl  = public_url('accept/' . $rawToken);
        $verifyUrl  = public_url('verify/' . $badgeUuid);
        $img        = badge_image_url((string) $badge['image_filename']);

        $inner = EmailTemplate::heading('¡Felicitaciones, ' . e($firstName) . '!')
            . '<p style="text-align:center;margin:0 0 8px"><strong>SecureHex</strong> creó esta acreditación a nombre de <strong>' . e($issuerName) . '</strong>:</p>'
            . EmailTemplate::badgeImage($img, $badgeName)
            . '<p style="text-align:center;font-size:18px;font-weight:700;margin:8px 0 2px;color:#0f1b2e">' . e($badgeName) . '</p>'
            . '<p style="text-align:center;color:#697587;margin:2px 0 0">Aceptala para sumarla a tu perfil, compartirla y verificarla.</p>'
            . EmailTemplate::button('Aceptar mi acreditación', $acceptUrl)
            . (CertificateService::hasCertificate($badge)
                ? '<p style="text-align:center;margin:4px 0 0"><a href="' . e(public_url('certificate/' . $badgeUuid . '.pdf')) . '" style="color:#1565d8;text-decoration:none;font-weight:600">⬇ Descargar tu diploma (PDF)</a></p>'
                : '')
            . EmailTemplate::muted('o <a href="' . e($verifyUrl) . '" style="color:#1565d8;text-decoration:none">ver la acreditación</a> · el enlace expira en 30 días');

        return [
            'badge_id'   => (int) $badge['id'],
            'company_id' => isset($badge['company_id']) ? (int) $badge['company_id'] : null,
            // Notificar al correo por el que se emitió (multi-correo); si es un
            // badge previo a la migración, cae al correo primario del earner.
            'to'         => (string) ($badge['recipient_email'] ?? $badge['earner_email']),
            'subject'    => 'Acreditación de ' . $issuerName . ': ' . $badgeName,
            'html'       => EmailTemplate::wrap($inner, 'SecureHex creó una acreditación a tu nombre: ' . $badgeName),
        ];
    }

    public function markNotificationSent(int $issuedBadgeId): void
    {
        IssuedBadge::updateById($issuedBadgeId, [
            'notification_sent'    => 1,
            'notification_sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{ok:bool, reason:?string, badge_uuid:?string, accept_token:?string, earner_id:?int}
     */
    private function fail(string $reason, ?string $uuid = null, ?string $token = null, ?int $earnerId = null): array
    {
        return ['ok' => false, 'reason' => $reason, 'badge_uuid' => $uuid, 'accept_token' => $token, 'earner_id' => $earnerId];
    }
}
