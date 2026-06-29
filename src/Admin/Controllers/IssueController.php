<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Services\BadgeService;
use InvalidArgumentException;

/**
 * Emisión individual de badges (CLAUDE.md §6.2).
 */
final class IssueController extends Controller
{
    public function form(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        return $this->view('issue/form', [
            'pageTitle'   => 'Emitir badge',
            'templates'   => BadgeTemplate::active($this->companyFilter($request)),
            'selected'    => $request->query('template', ''),
            'errors'      => [],
            'old'         => [],
        ]);
    }

    public function issue(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $v = new Validator();
        try {
            $templateUuid = $v->uuid((string) $request->input('template_id', ''));
            $email        = $v->email((string) $request->input('email', ''));
            $firstName    = $v->name((string) $request->input('first_name', ''));
            $lastName     = $v->name((string) $request->input('last_name', ''));
            $locale       = $v->locale((string) $request->input('locale', 'es'));
        } catch (InvalidArgumentException $e) {
            return $this->reShowForm($request, [$e->getMessage()]);
        }

        // El template elegido debe pertenecer a una empresa accesible.
        $template = BadgeTemplate::findByUuid($templateUuid);
        if ($template === null) {
            return $this->reShowForm($request, ['El template no existe o no está activo.']);
        }
        if ($this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $this->reShowForm($request, ['No tenés acceso a ese template.']);
        }

        $service = new BadgeService();
        $result  = $service->issue($templateUuid, $email, $firstName, $lastName, (int) Auth::id(), 'manual', $locale);

        if (!$result['ok']) {
            $msg = match ($result['reason']) {
                'duplicate'         => 'Ese receptor ya tiene este badge activo.',
                'template_not_found'=> 'El template no existe o no está activo.',
                default             => 'No se pudo emitir el badge.',
            };
            return $this->reShowForm($request, [$msg]);
        }

        // Notificación con enlace de aceptación (mismo correo de marca).
        $service->sendNotification((string) $result['badge_uuid'], (string) $result['accept_token']);

        Session::flash('success', 'Badge emitido y notificación enviada a ' . $email . '.');
        return $this->redirect('/admin/badges/' . $result['badge_uuid']);
    }

    private function reShowForm(Request $request, array $errors): Response
    {
        return $this->view('issue/form', [
            'pageTitle' => 'Emitir badge',
            'templates' => BadgeTemplate::active($this->companyFilter($request)),
            'selected'  => (string) $request->input('template_id', ''),
            'errors'    => $errors,
            'old'       => $request->all(),
        ], 422);
    }
}
