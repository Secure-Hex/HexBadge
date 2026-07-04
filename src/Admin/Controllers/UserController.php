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

            // Empresas del futuro usuario: superadmin=global; el resto necesita 1+.
            $companyIds = $this->allowedCompaniesFromRequest($request);
            if ($role !== 'superadmin' && $companyIds === []) {
                throw new InvalidArgumentException('Elegí al menos una empresa válida para el usuario.');
            }

            (new InvitationService())->invite($email, $role, (int) Auth::id(), $companyIds);
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

    /**
     * GET /admin/users/{uuid}/edit — editar las empresas de un usuario existente.
     */
    public function edit(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $user = User::findByUuid($uuid);
        if ($user === null || $user['role'] === 'superadmin') {
            // El superadmin es global: no se le asignan empresas.
            return Response::html('<h1>404 — Usuario no encontrado</h1>', 404);
        }
        if ($resp = $this->assertCanManageUser($user)) {
            return $resp;
        }
        return $this->view('users/edit', [
            'pageTitle'    => 'Empresas de ' . $user['name'],
            'user'         => $user,
            'companies'    => $this->companiesForSelector(),
            'assignedIds'  => User::companyIds((int) $user['id']),
            'errors'       => [],
        ]);
    }

    /**
     * POST /admin/users/{uuid} — guardar las empresas del usuario.
     */
    public function update(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);
        $user = User::findByUuid($uuid);
        if ($user === null || $user['role'] === 'superadmin') {
            return Response::html('<h1>404 — Usuario no encontrado</h1>', 404);
        }
        if ($resp = $this->assertCanManageUser($user)) {
            return $resp;
        }

        $companyIds = $this->allowedCompaniesFromRequest($request);
        if ($companyIds === []) {
            return $this->view('users/edit', [
                'pageTitle'   => 'Empresas de ' . $user['name'],
                'user'        => $user,
                'companies'   => $this->companiesForSelector(),
                'assignedIds' => User::companyIds((int) $user['id']),
                'errors'      => ['Elegí al menos una empresa válida.'],
            ], 422);
        }

        User::setCompanies((int) $user['id'], $companyIds);
        Session::flash('success', 'Empresas de ' . $user['name'] . ' actualizadas.');
        return $this->redirect('/admin/users');
    }

    /**
     * Ids de empresa enviados en company_ids[] que el usuario actual puede asignar.
     *
     * @return array<int,int>
     */
    private function allowedCompaniesFromRequest(Request $request): array
    {
        $raw = $request->all()['company_ids'] ?? [];
        $ids = array_map('intval', is_array($raw) ? $raw : []);
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id > 0 && $this->isCompanyAllowed($id)));
        // Admin con una sola empresa: no ve el selector, se le fuerza la suya.
        if ($ids === [] && !Auth::isSuperadmin()) {
            $mine = Auth::companyIds();
            if (count($mine) === 1) {
                return $mine;
            }
        }
        return $ids;
    }

    /**
     * Un sub-admin solo puede gestionar usuarios que comparten alguna de sus
     * empresas. El superadmin gestiona a cualquiera. 403 si no está permitido.
     */
    private function assertCanManageUser(array $user): ?Response
    {
        if (Auth::isSuperadmin()) {
            return null;
        }
        foreach (User::companyIds((int) $user['id']) as $cid) {
            if ($this->isCompanyAllowed($cid)) {
                return null;
            }
        }
        return Response::html('<h1>403 — Acceso denegado</h1>', 403);
    }
}
