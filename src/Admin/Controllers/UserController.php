<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\User;
use HexBadge\Models\UserInvitation;
use HexBadge\Services\InvitationService;
use InvalidArgumentException;

/**
 * Gestión de usuarios por invitación (CLAUDE.md §7 + cambio del cliente).
 *
 * No hay registro abierto: el superadmin invita por email; la persona define
 * su contraseña vía /accept-invite/{token}.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $companyFilter = $this->companyFilter($request);
        return $this->view('users/index', [
            'pageTitle'     => 'Usuarios',
            'users'         => User::allOrdered($companyFilter),
            'invitations'   => UserInvitation::listForAdmin($companyFilter),
            'errors'        => [],
            'companies'     => $this->companiesForSelector(),
            'companyFilter' => $companyFilter,
            'canInviteSuperadmin' => Auth::isSuperadmin(),
        ]);
    }

    public function invite(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $v = new Validator();
        // Un admin solo puede invitar admin/issuer (no superadmin).
        $allowedRoles = Auth::isSuperadmin() ? ['admin', 'issuer', 'superadmin'] : ['admin', 'issuer'];
        try {
            $email = $v->email((string) $request->input('email', ''));
            $role  = $v->inList((string) $request->input('role', 'issuer'), $allowedRoles, 'rol');

            // Empresa del futuro usuario: superadmin la elige; admin fuerza la suya.
            $companyId = $role === 'superadmin' ? null : $this->companyForWrite($request);
            if ($role !== 'superadmin' && ($companyId === null || !$this->isCompanyAllowed($companyId))) {
                throw new InvalidArgumentException('Elegí una empresa válida para el usuario.');
            }

            (new InvitationService())->invite($email, $role, (int) Auth::id(), $companyId);
            Session::flash('success', 'Invitación enviada a ' . $email . '.');
            return $this->redirect('/admin/users');
        } catch (InvalidArgumentException $e) {
            $companyFilter = $this->companyFilter($request);
            return $this->view('users/index', [
                'pageTitle'     => 'Usuarios',
                'users'         => User::allOrdered($companyFilter),
                'invitations'   => UserInvitation::listForAdmin($companyFilter),
                'errors'        => [$e->getMessage()],
                'companies'     => $this->companiesForSelector(),
                'companyFilter' => $companyFilter,
                'canInviteSuperadmin' => Auth::isSuperadmin(),
            ], 422);
        }
    }

    /**
     * GET /accept-invite/{token} — público.
     */
    public function showAccept(Request $request, string $token): Response
    {
        $inv = (new InvitationService())->findValid($token);
        if ($inv === null) {
            return $this->view('users/invite_invalid', ['pageTitle' => 'Invitación inválida'], 410);
        }
        return $this->view('users/accept_invite', [
            'pageTitle' => 'Aceptar invitación',
            'token'     => $token,
            'email'     => (string) $inv['email'],
            'role'      => (string) $inv['role'],
            'errors'    => [],
        ]);
    }

    /**
     * POST /accept-invite/{token} — público (crea el usuario).
     */
    public function accept(Request $request, string $token): Response
    {
        $this->verifyCsrf($request);

        $service = new InvitationService();
        $inv     = $service->findValid($token);
        if ($inv === null) {
            return $this->view('users/invite_invalid', ['pageTitle' => 'Invitación inválida'], 410);
        }

        $v = new Validator();
        try {
            $name     = $v->name((string) $request->input('name', ''));
            $password = $v->password((string) $request->input('password', ''));
            if (!hash_equals($password, (string) $request->input('password_confirm', ''))) {
                throw new InvalidArgumentException('Las contraseñas no coinciden.');
            }
            $service->accept($token, $name, $password);
            Session::flash('success', 'Cuenta creada. Ya podés iniciar sesión.');
            return $this->redirect('/login');
        } catch (InvalidArgumentException $e) {
            return $this->view('users/accept_invite', [
                'pageTitle' => 'Aceptar invitación',
                'token'     => $token,
                'email'     => (string) $inv['email'],
                'role'      => (string) $inv['role'],
                'errors'    => [$e->getMessage()],
            ], 422);
        }
    }
}
