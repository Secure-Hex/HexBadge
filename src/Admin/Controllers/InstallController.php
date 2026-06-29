<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\CSRF;
use HexBadge\Core\Installer;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Core\Validator;
use HexBadge\Core\View;
use InvalidArgumentException;

/**
 * Asistente web de instalación (primer arranque).
 *
 * Disponible únicamente mientras la app NO está instalada. Pide los datos
 * de conexión a la BD y la cuenta del administrador inicial, escribe el
 * .env, crea el schema y el superadmin.
 */
final class InstallController
{
    /**
     * GET /install — formulario del asistente.
     */
    public function show(Request $request): Response
    {
        Session::start();
        return $this->form();
    }

    /**
     * POST /install — procesa el asistente.
     */
    public function run(Request $request): Response
    {
        Session::start();
        CSRF::check($request);

        try {
            $db = [
                'host' => trim((string) $request->input('db_host', '')),
                'port' => (string) ((int) $request->input('db_port', '3306')),
                'name' => trim((string) $request->input('db_name', '')),
                'user' => trim((string) $request->input('db_user', '')),
                'pass' => (string) $request->input('db_pass', ''),
            ];

            foreach (['host', 'name', 'user'] as $field) {
                if ($db[$field] === '') {
                    throw new InvalidArgumentException('Completá todos los datos de la base de datos.');
                }
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $db['name'])) {
                throw new InvalidArgumentException('El nombre de la base de datos solo admite letras, números y guion bajo.');
            }

            $validator = new Validator();
            $admin = [
                'name'     => $validator->name((string) $request->input('admin_name', '')),
                'email'    => $validator->email((string) $request->input('admin_email', '')),
                'password' => $validator->password((string) $request->input('admin_password', '')),
            ];
            if (!hash_equals($admin['password'], (string) $request->input('admin_password_confirm', ''))) {
                throw new InvalidArgumentException('Las contraseñas no coinciden.');
            }

            $earnerUrlRaw = trim((string) $request->input('app_earner_url', ''));
            $app = [
                'name'       => $validator->name((string) $request->input('app_name', 'HexBadge'), 100),
                'url'        => $validator->url((string) $request->input('app_url', ''), true),
                'earner_url' => $earnerUrlRaw === '' ? '' : $validator->url($earnerUrlRaw, false),
            ];

            Installer::install($db, $admin, $app);
        } catch (\Throwable $e) {
            return $this->form($e->getMessage(), $request->all());
        }

        Session::flash('success', 'Instalación completada. Ya podés iniciar sesión.');
        return Response::redirect('/login');
    }

    /**
     * GET /install cuando la app ya está instalada.
     */
    public function alreadyInstalled(Request $request): Response
    {
        return Response::html('<h1>HexBadge ya está instalado.</h1><p><a href="/login">Ir al login</a></p>', 410);
    }

    /**
     * @param array<string,mixed> $old
     */
    private function form(?string $error = null, array $old = []): Response
    {
        View::setBasePath(BASE_PATH . '/src/Admin/Views');
        $html = View::renderPartial('install/wizard', [
            'error'   => $error,
            'old'     => $old,
            'csrf'    => CSRF::field(),
            'appName' => 'HexBadge',
        ]);
        // Render sin layout/nav (todavía no hay sesión ni assets garantizados).
        return Response::html($html, $error !== null ? 422 : 200);
    }
}
