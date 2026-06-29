<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Logger;
use HexBadge\Models\Company;
use HexBadge\Models\User;
use HexBadge\Models\UserInvitation;
use InvalidArgumentException;

/**
 * Alta de usuarios del panel por invitación (no hay registro abierto).
 *
 * El invitador genera una invitación con token; la persona recibe un email,
 * abre el enlace y define su contraseña, quedando como usuario activo.
 */
final class InvitationService
{
    public const VALID_ROLES = ['admin', 'issuer', 'superadmin'];

    /**
     * Crea una invitación y envía el email. Devuelve el token crudo.
     */
    public function invite(string $email, string $role, int $invitedBy, ?int $companyId = null): string
    {
        $email = strtolower($email);

        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException('Rol inválido');
        }
        if (User::findByEmail($email) !== null) {
            throw new InvalidArgumentException('Ya existe un usuario con ese email');
        }
        if (UserInvitation::hasPending($email)) {
            throw new InvalidArgumentException('Ya hay una invitación pendiente para ese email');
        }
        // Un superadmin es global (sin empresa); el resto de roles requiere una.
        if ($role !== 'superadmin' && $companyId === null) {
            throw new InvalidArgumentException('Elegí la empresa para este usuario');
        }
        if ($role === 'superadmin') {
            $companyId = null;
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 86400)); // 7 días

        UserInvitation::create([
            'uuid'       => uuid4(),
            'email'      => $email,
            'role'       => $role,
            'company_id' => $companyId,
            'token_hash' => $tokenHash,
            'invited_by' => $invitedBy,
            'expires_at' => $expiresAt,
        ]);

        $companyName = $companyId !== null ? (string) (Company::find($companyId)['name'] ?? '') : null;
        $this->sendEmail($email, $rawToken, $role, $companyName !== '' ? $companyName : null, $companyId);
        Logger::audit('user.invited', $invitedBy, 'user_invitation', null, ['email' => $email, 'role' => $role, 'company_id' => $companyId]);

        return $rawToken;
    }

    /**
     * Devuelve la invitación válida (no aceptada/expirada) por token crudo.
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $rawToken): ?array
    {
        $inv = UserInvitation::findByTokenHash(hash('sha256', $rawToken));
        if ($inv === null || $inv['accepted_at'] !== null) {
            return null;
        }
        if (strtotime((string) $inv['expires_at']) < time()) {
            return null;
        }
        return $inv;
    }

    /**
     * Acepta una invitación creando el usuario. Devuelve el ID del usuario.
     */
    public function accept(string $rawToken, string $name, string $password): int
    {
        $inv = $this->findValid($rawToken);
        if ($inv === null) {
            throw new InvalidArgumentException('Invitación inválida o expirada');
        }
        if (User::findByEmail((string) $inv['email']) !== null) {
            throw new InvalidArgumentException('El usuario ya existe');
        }

        $userId = User::create([
            'uuid'          => uuid4(),
            'name'          => $name,
            'email'         => (string) $inv['email'],
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'role'          => (string) $inv['role'],
            'company_id'    => $inv['company_id'] !== null ? (int) $inv['company_id'] : null,
            'is_active'     => 1,
        ]);

        UserInvitation::updateById((int) $inv['id'], ['accepted_at' => date('Y-m-d H:i:s')]);
        Logger::audit('user.invitation.accepted', $userId, 'user', null, ['email' => $inv['email']]);

        return $userId;
    }

    private function sendEmail(string $email, string $rawToken, string $role, ?string $companyName = null, ?int $companyId = null): void
    {
        $url = rtrim((string) config('app.url'), '/') . '/accept-invite/' . $rawToken;
        $app = (string) config('app.name', 'HexBadge');

        $roleLabel = match ($role) {
            'superadmin' => 'Superadministrador',
            'admin'      => 'Administrador',
            default      => 'Emisor',
        };

        if ($companyName !== null) {
            $intro   = 'Te invitaron a gestionar las acreditaciones de <strong>' . e($companyName)
                     . '</strong> con el rol <strong>' . e($roleLabel) . '</strong>.';
            $subject = 'Invitación a ' . $companyName . ' — ' . $app;
        } else {
            $intro   = 'Te invitaron como <strong>' . e($roleLabel) . '</strong> de <strong>' . e($app)
                     . '</strong> (acceso a todas las empresas).';
            $subject = 'Invitación a ' . $app;
        }

        $inner = EmailTemplate::heading('Te invitaron a ' . e($app))
               . '<p style="text-align:center;margin:0 0 4px">' . $intro . '</p>'
               . '<p style="text-align:center;color:#697587;margin:6px 0 0">Hacé clic para crear tu cuenta y definir tu contraseña.</p>'
               . EmailTemplate::button('Aceptar invitación', $url)
               . EmailTemplate::muted('El enlace expira en 7 días. Si no esperabas esto, ignorá este correo.');

        $html = EmailTemplate::wrap($inner, $subject);
        (new EmailService())->send($email, $subject, $html, $companyId);
    }
}
