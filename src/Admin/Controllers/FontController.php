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
use HexBadge\Models\Font;

/**
 * Gestión de tipografías para los certificados: subir TTF/OTF, listar, borrar.
 * Las fuentes se guardan en storage/fonts (privado; las usa GD por ruta).
 */
final class FontController extends Controller
{
    private const FONT_DIR = BASE_PATH . '/storage/fonts/';

    public function index(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        return $this->view('settings/fonts', [
            'pageTitle' => 'Tipografías',
            'fonts'     => Font::allOrdered(),
            'errors'    => [],
        ]);
    }

    public function store(Request $request): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        try {
            $name = (new Validator())->name((string) $request->input('name', ''), 100);
            $file = $request->file('font');
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new \InvalidArgumentException('Subí un archivo de fuente.');
            }
            if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new \InvalidArgumentException('La fuente supera 5MB.');
            }

            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['ttf', 'otf'], true)) {
                throw new \InvalidArgumentException('Solo se permiten archivos .ttf o .otf.');
            }
            // Magic bytes de fuente (TTF: 0x00010000 / 'true'; OTF: 'OTTO').
            $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4);
            $sigs = ["\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf'];
            if (!in_array($head, $sigs, true)) {
                throw new \InvalidArgumentException('El archivo no parece una fuente válida.');
            }

            if (!is_dir(self::FONT_DIR) && !mkdir(self::FONT_DIR, 0750, true) && !is_dir(self::FONT_DIR)) {
                throw new \RuntimeException('No se pudo crear el directorio de fuentes.');
            }
            $filename = bin2hex(random_bytes(12)) . '.' . $ext;
            $dest     = self::FONT_DIR . $filename;
            if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
                throw new \RuntimeException('No se pudo guardar la fuente.');
            }
            @chmod($dest, 0644);

            Font::create(['name' => $name, 'file_path' => 'storage/fonts/' . $filename, 'is_builtin' => 0]);
            Logger::audit('font.uploaded', Auth::id(), 'font', null, ['name' => $name]);
            Session::flash('success', 'Fuente "' . $name . '" agregada.');
            return $this->redirect('/admin/fonts');
        } catch (\InvalidArgumentException $e) {
            return $this->view('settings/fonts', [
                'pageTitle' => 'Tipografías',
                'fonts'     => Font::allOrdered(),
                'errors'    => [$e->getMessage()],
            ], 422);
        }
    }

    /**
     * Sirve el archivo de la fuente al navegador (para la vista previa en vivo
     * del marcado). Lo puede pedir cualquier emisor que esté configurando un
     * certificado, no solo admin.
     */
    public function file(Request $request, string $id): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $path = Font::pathFor((int) $id);
        if ($path === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        $bytes = (string) @file_get_contents($path);
        $ctype = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'otf' ? 'font/otf' : 'font/ttf';
        return new Response($bytes, 200, [
            'Content-Type'   => $ctype,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control'  => 'private, max-age=86400',
        ]);
    }

    public function delete(Request $request, string $id): Response
    {
        if ($r = Auth::requireRole('admin')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $font = Font::find((int) $id);
        if ($font !== null && (int) $font['is_builtin'] === 0) {
            $path = BASE_PATH . '/' . ltrim((string) $font['file_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
            $this->db()->query('DELETE FROM fonts WHERE id = ? AND is_builtin = 0', [(int) $id]);
            Logger::audit('font.deleted', Auth::id(), 'font', null, ['id' => (int) $id]);
            Session::flash('success', 'Fuente eliminada.');
        }
        return $this->redirect('/admin/fonts');
    }
}
