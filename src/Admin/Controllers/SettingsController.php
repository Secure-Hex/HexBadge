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
use HexBadge\Services\EmailService;
use HexBadge\Services\SettingsService;

/**
 * Configuración de la plataforma (SMTP) — rol admin+.
 */
final class SettingsController extends Controller
{
    private const SMTP_KEYS = [
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
        'smtp_from_address', 'smtp_from_name',
    ];

    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        return $this->view('settings/smtp', [
            'pageTitle'   => 'Configuración',
            'smtp'        => SettingsService::getMany(self::SMTP_KEYS),
            'hasPassword' => SettingsService::get('smtp_password') !== '',
            'errors'      => [],
        ]);
    }

    public function save(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $v = new Validator();
        try {
            $values = [
                'smtp_host'         => trim((string) $request->input('smtp_host', '')),
                'smtp_port'         => (string) $v->int((string) $request->input('smtp_port', '587'), 1, 65535),
                'smtp_username'     => trim((string) $request->input('smtp_username', '')),
                'smtp_encryption'   => $v->inList((string) $request->input('smtp_encryption', 'tls'), ['tls', 'ssl', 'none'], 'cifrado'),
                'smtp_from_address' => $request->input('smtp_from_address', '') !== '' ? $v->email((string) $request->input('smtp_from_address', '')) : '',
                'smtp_from_name'    => trim((string) $request->input('smtp_from_name', 'HexBadge')),
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->view('settings/smtp', [
                'pageTitle'   => 'Configuración SMTP',
                'smtp'        => array_merge(SettingsService::getMany(self::SMTP_KEYS), $request->all()),
                'hasPassword' => SettingsService::get('smtp_password') !== '',
                'errors'      => [$e->getMessage()],
            ], 422);
        }

        SettingsService::setMany($values);

        // La contraseña solo se actualiza si se ingresó una nueva.
        $newPass = (string) $request->input('smtp_password', '');
        if ($newPass !== '') {
            SettingsService::set('smtp_password', $newPass);
        }

        Logger::audit('settings.smtp.updated', Auth::id(), 'settings', null, ['host' => $values['smtp_host']]);
        Session::flash('success', 'Configuración SMTP guardada.');
        return $this->redirect('/admin/settings');
    }

    public function test(Request $request): Response
    {
        if ($r = Auth::requireRole('superadmin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $to = (string) $request->input('test_email', '');
        try {
            $to = (new Validator())->email($to);
            (new EmailService())->sendTest($to);
            Session::flash('success', 'Correo de prueba enviado a ' . $to . '.');
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo enviar: ' . $e->getMessage());
        }
        return $this->redirect('/admin/settings');
    }
}
