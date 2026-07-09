<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;

/**
 * Fusión de wallets del receptor (multi-correo).
 *
 * Flujo: el earner A inicia el vínculo de un correo B (startLink) → se envía un
 * enlace de verificación a B → al abrirlo (posesión probada), B se fusiona en A
 * (applyMerge): sus badges se mueven a A, su correo pasa a ser un correo más de
 * A, y la cuenta B queda marcada como fusionada (soft, reversible en F3).
 *
 * La verificación por email es un gate OBLIGATORIO: la fusión nunca ocurre sin el
 * clic desde el correo B (evita apropiarse de acreditaciones ajenas no aceptadas).
 */
final class WalletMergeService
{
    /** Campos de perfil que se pueden conservar del origen al fusionar. */
    public const PROFILE_FIELDS = [
        'first_name', 'last_name', 'profile_bio', 'profile_url',
        'avatar_filename', 'cover_filename',
        'linkedin_url', 'instagram_url', 'x_url', 'github_url',
    ];

    private const VERIFY_TTL = 2 * 86400;  // 2 días para confirmar el vínculo
    private const REVERT_TTL = 7 * 86400;  // 7 días para deshacer (usado en F3)

    private Database $db;
    private OpenBadgeService $ob;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ob = new OpenBadgeService();
    }

    /**
     * Inicia el vínculo de un correo: crea la solicitud y envía la verificación
     * a ese correo. No revela si el correo pertenece a otra cuenta
     * (anti-enumeración); solo rechaza si ya es un correo de la propia cuenta.
     *
     * @throws \InvalidArgumentException si el correo ya está vinculado al target.
     */
    public function startLink(int $targetEarnerId, string $email): void
    {
        $email  = strtolower(trim($email));
        $target = Earner::find($targetEarnerId);
        if ($target === null) {
            throw new \InvalidArgumentException('Cuenta no encontrada.');
        }
        if (in_array($email, $this->emailsOf($targetEarnerId), true)) {
            throw new \InvalidArgumentException('Ese correo ya está vinculado a tu cuenta.');
        }

        $raw = bin2hex(random_bytes(32));
        $this->db->insert('earner_merges', [
            'target_earner_id'  => $targetEarnerId,
            'source_email'      => $email,
            'verify_token_hash' => hash('sha256', $raw),
            'verify_expires'    => date('Y-m-d H:i:s', time() + self::VERIFY_TTL),
            'status'            => 'pending',
        ]);

        $this->sendVerifyEmail($email, $target, $raw);
        Logger::audit('earner.merge.requested', null, 'earner', (string) $target['uuid'], ['to' => $email]);
    }

    /**
     * Solicitud pendiente por token de verificación (válida y no expirada).
     *
     * @return array<string,mixed>|null
     */
    public function findByVerifyToken(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }
        return $this->db->fetchOne(
            "SELECT * FROM earner_merges
             WHERE verify_token_hash = ? AND status = 'pending' AND verify_expires > NOW()
             LIMIT 1",
            [hash('sha256', $rawToken)]
        );
    }

    /**
     * Aplica la fusión (transaccional). Mueve los badges del correo origen a la
     * cuenta destino, vincula el correo, aplica los campos de perfil elegidos y
     * marca la cuenta origen como fusionada. Devuelve el token de reversión en
     * claro (para el aviso "deshacer" de F3) y el uuid del destino.
     *
     * @param array<string,mixed> $merge   Fila de earner_merges (pending).
     * @param array<string,string> $choices field => 'source' para conservar el del origen.
     * @return array{revert_token:string, target_uuid:string, moved:int}
     */
    public function applyMerge(array $merge, array $choices): array
    {
        $targetId    = (int) $merge['target_earner_id'];
        $sourceEmail = strtolower((string) $merge['source_email']);

        $target = Earner::find($targetId);
        if ($target === null || $target['merged_into_id'] !== null) {
            throw new \RuntimeException('La cuenta destino ya no está disponible.');
        }
        $source   = Earner::findByEmail($sourceEmail);
        $sourceId = ($source !== null && (int) $source['id'] !== $targetId) ? (int) $source['id'] : null;

        $revertRaw   = bin2hex(random_bytes(32));
        $movedIds    = [];
        $movedEmails = [];
        $profilePrev = [];  // valores previos del destino (para poder revertir)

        $this->db->beginTransaction();
        try {
            if ($sourceId !== null) {
                $movedEmails = $this->emailsOf($sourceId);
                foreach ($this->db->fetchAll('SELECT id FROM issued_badges WHERE earner_id = ?', [$sourceId]) as $r) {
                    $movedIds[] = (int) $r['id'];
                }
                $this->db->query(
                    'UPDATE issued_badges
                     SET earner_id = ?, recipient_email = COALESCE(recipient_email, ?)
                     WHERE earner_id = ?',
                    [$targetId, $sourceEmail, $sourceId]
                );
                // Vincular todos los correos del origen a la cuenta destino.
                $this->db->query(
                    'UPDATE earner_emails SET earner_id = ?, is_primary = 0 WHERE earner_id = ?',
                    [$targetId, $sourceId]
                );
                // Aplicar los campos de perfil elegidos del origen, guardando el
                // valor previo del destino para poder restaurarlo al revertir.
                $updates = [];
                foreach (self::PROFILE_FIELDS as $f) {
                    if (($choices[$f] ?? 'target') === 'source' && ($source[$f] ?? null) !== null && $source[$f] !== '') {
                        $updates[$f]     = $source[$f];
                        $profilePrev[$f] = $target[$f] ?? null;
                    }
                }
                if ($updates !== []) {
                    Earner::updateById($targetId, $updates);
                }
                // Marcar la cuenta origen como fusionada (soft).
                $this->db->query(
                    'UPDATE earners SET merged_into_id = ?, merged_at = NOW() WHERE id = ?',
                    [$targetId, $sourceId]
                );
                // Regenerar las assertions Open Badge de los badges movidos.
                $this->regenerateAssertions($movedIds);
            } else {
                // El correo no pertenece a ninguna cuenta: solo vincularlo.
                $movedEmails = [$sourceEmail];
                $this->db->insert('earner_emails', [
                    'earner_id' => $targetId, 'email' => $sourceEmail, 'is_primary' => 0,
                ]);
            }

            // Estado necesario para deshacer: perfil previo del destino + correos movidos.
            $snapshot = json_encode(['profile_prev' => $profilePrev, 'emails' => $movedEmails], JSON_UNESCAPED_UNICODE);
            $this->db->update('earner_merges', [
                'source_earner_id'  => $sourceId,
                'moved_badge_ids'   => json_encode($movedIds),
                'profile_choices'   => json_encode($choices, JSON_UNESCAPED_UNICODE),
                'source_snapshot'   => $snapshot,
                'revert_token_hash' => hash('sha256', $revertRaw),
                'revert_expires'    => date('Y-m-d H:i:s', time() + self::REVERT_TTL),
                'status'            => 'active',
            ], 'id = ?', [(int) $merge['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Logger::audit('earner.merge.applied', null, 'earner', (string) $target['uuid'], [
            'source_email' => $sourceEmail, 'moved' => count($movedIds),
        ]);
        // Avisar a AMBOS correos, con enlace para deshacer.
        $this->sendMergeNotices((string) $target['email'], $sourceEmail, (string) ($target['display_name'] ?? ''), $revertRaw, count($movedIds));

        return [
            'revert_token' => $revertRaw,
            'target_uuid'  => (string) $target['uuid'],
            'moved'        => count($movedIds),
        ];
    }

    /** @return array<int,string> */
    public function emailsOf(int $earnerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT email FROM earner_emails WHERE earner_id = ? ORDER BY is_primary DESC, created_at ASC',
            [$earnerId]
        );
        return array_map(static fn (array $r): string => (string) $r['email'], $rows);
    }

    /**
     * @param array<int,int> $badgeIds
     */
    private function regenerateAssertions(array $badgeIds): void
    {
        foreach ($badgeIds as $id) {
            $row = $this->db->fetchOne('SELECT uuid FROM issued_badges WHERE id = ?', [$id]);
            if ($row === null) {
                continue;
            }
            $full = IssuedBadge::findFullByUuid((string) $row['uuid']);
            if ($full !== null) {
                $assertion = $this->ob->buildAssertion($full);
                IssuedBadge::updateById($id, ['ob_assertion_json' => json_encode($assertion, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /**
     * Fusión activa por token de reversión (válido y no vencido).
     *
     * @return array<string,mixed>|null
     */
    public function findByRevertToken(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }
        return $this->db->fetchOne(
            "SELECT * FROM earner_merges
             WHERE revert_token_hash = ? AND status = 'active' AND revert_expires > NOW()
             LIMIT 1",
            [hash('sha256', $rawToken)]
        );
    }

    /**
     * Deshace una fusión: devuelve los badges y correos a la cuenta origen,
     * restaura el perfil previo del destino y reactiva la cuenta origen.
     *
     * @param array<string,mixed> $merge Fila de earner_merges (active).
     * @return array{source_email:string}
     */
    public function revertMerge(array $merge): array
    {
        $targetId    = (int) $merge['target_earner_id'];
        $sourceId    = $merge['source_earner_id'] !== null ? (int) $merge['source_earner_id'] : null;
        $sourceEmail = strtolower((string) $merge['source_email']);
        $snap        = json_decode((string) ($merge['source_snapshot'] ?? ''), true) ?: [];
        $movedIds    = json_decode((string) ($merge['moved_badge_ids'] ?? '[]'), true) ?: [];
        $emails      = is_array($snap['emails'] ?? null) ? $snap['emails'] : [];
        $profilePrev = is_array($snap['profile_prev'] ?? null) ? $snap['profile_prev'] : [];

        $this->db->beginTransaction();
        try {
            if ($sourceId !== null) {
                if ($movedIds !== []) {
                    $in = implode(',', array_fill(0, count($movedIds), '?'));
                    $this->db->query("UPDATE issued_badges SET earner_id = ? WHERE id IN ($in)", array_merge([$sourceId], $movedIds));
                }
                if ($emails !== []) {
                    $in = implode(',', array_fill(0, count($emails), '?'));
                    $this->db->query("UPDATE earner_emails SET earner_id = ? WHERE email IN ($in)", array_merge([$sourceId], $emails));
                    $this->db->query("UPDATE earner_emails SET is_primary = 1 WHERE earner_id = ? AND email = ?", [$sourceId, $sourceEmail]);
                }
                if ($profilePrev !== []) {
                    Earner::updateById($targetId, $profilePrev);
                }
                $this->db->query("UPDATE earners SET merged_into_id = NULL, merged_at = NULL WHERE id = ?", [$sourceId]);
                $this->regenerateAssertions(array_map('intval', $movedIds));
            } else {
                // Solo se había vinculado el correo: quitarlo del destino.
                $this->db->query("DELETE FROM earner_emails WHERE earner_id = ? AND email = ?", [$targetId, $sourceEmail]);
            }

            $this->db->update('earner_merges', ['status' => 'reverted'], 'id = ?', [(int) $merge['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Logger::audit('earner.merge.reverted', null, 'earner', (string) ($merge['id'] ?? ''), ['source_email' => $sourceEmail]);
        $target = Earner::find($targetId);
        $this->sendRevertNotices((string) ($target['email'] ?? ''), $sourceEmail);

        return ['source_email' => $sourceEmail];
    }

    /**
     * Avisa a ambos correos que se unieron las wallets, con enlace para deshacer.
     */
    private function sendMergeNotices(string $targetEmail, string $sourceEmail, string $targetName, string $revertRaw, int $moved): void
    {
        $undoUrl = public_url('me/merge/revert/' . $revertRaw);
        $who     = trim($targetName) !== '' ? e(trim($targetName)) : e($targetEmail);
        $movedTxt = $moved > 0 ? $moved . ' acreditación(es) pasaron a esa wallet.' : 'No había acreditaciones que mover.';

        $inner = EmailTemplate::heading('Se unieron dos wallets')
            . '<p style="text-align:center;margin:0 0 4px">Hola,</p>'
            . '<p style="text-align:center;color:#697587;margin:6px 0 0">'
            . 'Los correos <strong>' . e($targetEmail) . '</strong> y <strong>' . e($sourceEmail) . '</strong> '
            . 'quedaron unidos en la wallet de <strong>' . $who . '</strong>. ' . e($movedTxt) . '</p>'
            . '<p style="text-align:center;color:#697587;margin:10px 0 0">Si no reconocés este cambio, podés deshacerlo:</p>'
            . EmailTemplate::button('Deshacer la unión', $undoUrl)
            . EmailTemplate::muted('Podés deshacer durante los próximos 7 días. Pasado ese plazo, la unión queda firme.');

        $this->sendToBoth([$targetEmail, $sourceEmail], 'Se unieron tus acreditaciones', EmailTemplate::wrap($inner, 'Se unieron dos wallets'));
    }

    /**
     * Confirma a ambos correos que la unión se deshizo.
     */
    private function sendRevertNotices(string $targetEmail, string $sourceEmail): void
    {
        $inner = EmailTemplate::heading('Se deshizo la unión de wallets')
            . '<p style="text-align:center;color:#697587;margin:6px 0 0">'
            . 'Volvimos a separar las acreditaciones de <strong>' . e($sourceEmail) . '</strong> '
            . 'de la wallet de <strong>' . e($targetEmail) . '</strong>. Cada correo vuelve a tener su propia wallet.</p>';
        $this->sendToBoth([$targetEmail, $sourceEmail], 'Se deshizo la unión de tus acreditaciones', EmailTemplate::wrap($inner, 'Se deshizo la unión de wallets'));
    }

    /**
     * Envía el mismo correo a ambas direcciones (deduplicadas). Tolerante a
     * fallos: un error de SMTP no interrumpe la fusión/reversión ya aplicada.
     *
     * @param array<int,string> $recipients
     */
    private function sendToBoth(array $recipients, string $subject, string $html): void
    {
        $mailer = new EmailService();
        foreach (array_unique($recipients) as $to) {
            if ($to === '') {
                continue;
            }
            try {
                $mailer->send($to, $subject, $html);
            } catch (\Throwable $e) {
                Logger::app('warning', 'No se pudo enviar aviso de fusión a ' . $to . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string,mixed> $target
     */
    private function sendVerifyEmail(string $to, array $target, string $rawToken): void
    {
        $url  = public_url('me/merge/' . $rawToken);
        $name = trim((string) ($target['display_name'] ?? ''));
        $who  = $name !== '' ? e($name) : 'otra persona';

        $inner = EmailTemplate::heading('¿Unir esta wallet con otra cuenta?')
            . '<p style="text-align:center;margin:0 0 4px">Hola,</p>'
            . '<p style="text-align:center;color:#697587;margin:6px 0 0">'
            . 'La cuenta de <strong>' . $who . '</strong> pidió vincular este correo para '
            . 'unir sus acreditaciones en una sola wallet. Si fuiste vos, confirmá abajo. '
            . 'Al confirmar, las acreditaciones enviadas a este correo pasarán a esa cuenta.</p>'
            . EmailTemplate::button('Confirmar y unir mis acreditaciones', $url)
            . EmailTemplate::muted('El enlace vence en 48 horas. Si no reconocés este pedido, ignorá este correo: no se hará ningún cambio.');

        (new EmailService())->send($to, 'Confirmá la unión de tus acreditaciones', EmailTemplate::wrap($inner, 'Confirmá la unión de tus acreditaciones'));
    }
}
