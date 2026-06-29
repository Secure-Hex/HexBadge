<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\ApiKey;
use HexBadge\Services\ApiKeyService;

/**
 * Gestión de API keys del usuario (CLAUDE.md §4.5, §7).
 */
final class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        return $this->view('apikeys/index', [
            'pageTitle'   => 'API Keys',
            'keys'        => ApiKey::forUser((int) Auth::id()),
            'scopes'      => ApiKeyService::SCOPES,
            'newKey'      => Session::flash('new_api_key'),
            'errors'      => [],
        ]);
    }

    public function store(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $v = new Validator();
        try {
            $name = $v->name((string) $request->input('name', ''), 100);
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            return $this->redirect('/admin/api-keys');
        }

        $scopes = $request->all()['scopes'] ?? [];
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        $result = (new ApiKeyService())->generate((int) Auth::id(), $name, $scopes);
        Logger::audit('apikey.created', Auth::id(), 'api_key', null, ['name' => $name, 'scopes' => $scopes]);

        // Mostrar el secreto UNA sola vez vía flash.
        Session::flash('new_api_key', $result['key']);
        Session::flash('success', 'API key creada. Copiala ahora: no se vuelve a mostrar.');
        return $this->redirect('/admin/api-keys');
    }

    public function revoke(Request $request, string $id): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $ok = (new ApiKeyService())->revoke((int) $id, (int) Auth::id());
        Logger::audit('apikey.revoked', Auth::id(), 'api_key', null, ['id' => (int) $id]);
        Session::flash($ok ? 'success' : 'error', $ok ? 'API key revocada.' : 'No se pudo revocar.');
        return $this->redirect('/admin/api-keys');
    }
}
