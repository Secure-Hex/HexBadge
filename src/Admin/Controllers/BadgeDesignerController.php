<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Database;
use HexBadge\Core\Logger;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Services\BadgeSvgService;
use HexBadge\Services\ImageService;

/**
 * Diseñador de insignias: pantalla propia, como el marcado de diplomas.
 *
 * La vista previa la arma el servidor con el mismo `render()` que produce el
 * archivo guardado, así que no puede haber diferencia entre lo que se ve y lo
 * que queda. El navegador solo posiciona: arrastrar una capa cambia números en
 * la receta y vuelve a pedir el dibujo.
 */
final class BadgeDesignerController extends Controller
{
    /** Peso máximo de la receta que llega del cliente. */
    private const MAX_RECIPE = 8192;

    public function show(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::notFound('Esa acreditación no existe.');
        }
        if ($resp = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $resp;
        }

        $decoded = json_decode((string) ($template['design_recipe'] ?? ''), true);
        $recipe  = BadgeSvgService::sanitize(is_array($decoded) ? $decoded : [
            'title' => (string) $template['name'],
        ]);

        return $this->view('badges/designer', [
            'pageTitle' => 'Diseñar insignia',
            'template'  => $template,
            'recipe'    => $recipe,
            'assets'    => $this->assets((int) ($template['company_id'] ?? 0)),
            'issued'    => (int) ($template['badges_issued'] ?? 0),
        ]);
    }

    /** Vista previa en SVG de la receta que llega por query. */
    public function preview(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $raw = (string) $request->query('r', '{}');
        if (strlen($raw) > self::MAX_RECIPE) {
            return Response::notFound();
        }
        $decoded = json_decode($raw, true);
        $svg     = (new BadgeSvgService())->render(is_array($decoded) ? $decoded : []);

        return new Response($svg, 200, [
            'Content-Type'  => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /** Guarda la receta y genera los dos archivos. */
    public function save(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::notFound('Esa acreditación no existe.');
        }
        if ($resp = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $resp;
        }

        $raw = (string) $request->input('recipe', '{}');
        if (strlen($raw) > self::MAX_RECIPE) {
            Session::flash('error', 'El diseño es demasiado grande.');
            return $this->redirect('/admin/templates/' . $uuid . '/designer');
        }

        $decoded = json_decode($raw, true);
        $recipe  = BadgeSvgService::sanitize(is_array($decoded) ? $decoded : []);
        $svg     = (new BadgeSvgService())->render($recipe);

        $image = new ImageService();
        $prev  = (string) $template['image_filename'];
        // Se reescribe el mismo archivo cuando ya era un diseño: los correos ya
        // enviados embeben su URL y cambiar el nombre los deja rotos.
        $reuse = !empty($template['design_recipe']) && str_ends_with($prev, '.svg') ? $prev : null;
        $name  = $image->storeGeneratedSvg($svg, $reuse);

        if ($reuse === null && $prev !== '') {
            $image->delete($prev);
        }

        BadgeTemplate::updateById((int) $template['id'], [
            'image_filename' => $name,
            'design_recipe'  => json_encode($recipe, JSON_UNESCAPED_UNICODE),
        ]);

        Logger::audit('template.designed', Auth::id(), 'badge_template', $uuid, [
            'shape'  => $recipe['shape'],
            'images' => count($recipe['images']),
        ]);

        Session::flash('success', 'Diseño guardado.');

        return $this->redirect('/admin/templates/' . $uuid . '/designer');
    }

    /** Sube una imagen para usar como capa. */
    public function uploadAsset(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $template = BadgeTemplate::findByUuid($uuid);
        if ($template === null) {
            return Response::notFound('Esa acreditación no existe.');
        }
        if ($resp = $this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $resp;
        }

        $file = $request->file('asset');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Elegí una imagen para subir.');
        } else {
            try {
                // Reutiliza el mismo procesado que los logos: valida el tipo
                // real, sanea el SVG y limita el tamaño.
                (new ImageService())->processLogo($file);
                Session::flash('success', 'Imagen agregada a la biblioteca.');
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
            }
        }

        return $this->redirect('/admin/templates/' . $uuid . '/designer');
    }

    /**
     * Imágenes que se pueden usar como capa: los logos cargados.
     *
     * @return array<int,array{dir:string,file:string,url:string}>
     */
    private function assets(int $companyId): array
    {
        $out = [];
        $dir = BASE_PATH . '/apps/earner/public/uploads/logos/';
        foreach (glob($dir . '*') ?: [] as $path) {
            if (!is_file($path) || basename($path) === '.htaccess') {
                continue;
            }
            $out[] = [
                'dir'  => 'logo',
                'file' => basename($path),
                'url'  => logo_image_url(basename($path)),
            ];
        }

        return $out;
    }
}
