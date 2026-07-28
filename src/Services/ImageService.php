<?php

declare(strict_types=1);

namespace HexBadge\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Procesamiento seguro de imágenes de badges (CLAUDE.md §4.4).
 *
 * - Valida tamaño y MIME real (finfo, no $_FILES['type']).
 * - Sanitiza SVG (elimina scripts y handlers).
 * - Nombre aleatorio (nunca el del usuario), permisos restrictivos.
 */
final class ImageService
{
    // Las imágenes viven en el docroot PÚBLICO (portal de personas), no en admin.
    private const UPLOAD_DIR   = BASE_PATH . '/apps/earner/public/uploads/badges/';
    private const PROFILE_DIR  = BASE_PATH . '/apps/earner/public/uploads/profiles/';
    private const CERT_DIR     = BASE_PATH . '/apps/earner/public/uploads/certificates/';
    private const LOGO_DIR     = BASE_PATH . '/apps/earner/public/uploads/logos/';

    /**
     * Lado máximo al que se guarda cada tipo de imagen, en píxeles.
     *
     * No es una preferencia estética: una insignia se muestra a 112px en la
     * grilla del perfil y a 224px en la vitrina de la credencial, así que 512
     * cubre pantallas de densidad doble y deja margen para el `og:image` y para
     * la imagen que expone la afirmación de Open Badges. Guardarlas a 1254px,
     * como venía pasando, hacía que un perfil con siete insignias descargara
     * más de 5 MB.
     *
     * Las plantillas de diploma NO pasan por acá: se imprimen en un PDF y
     * necesitan su resolución original.
     */
    private const MAX_EDGE_BADGE   = 512;
    private const MAX_EDGE_PROFILE = 1280;  // las portadas ocupan el ancho de la tarjeta
    private const MAX_EDGE_LOGO    = 600;

    private int $maxBytes;

    public function __construct()
    {
        $this->maxBytes = ((int) config('upload.max_size_mb', 2)) * 1024 * 1024;
    }

    /**
     * Procesa la imagen de un badge (PNG/JPG/SVG sanitizado) y devuelve el
     * nombre final almacenado.
     *
     * @param array<string,mixed> $file Entrada de $_FILES (vía Request::file()).
     */
    public function processUpload(array $file): string
    {
        return $this->persist($file, self::UPLOAD_DIR, [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/svg+xml' => 'svg',
        ], $this->maxBytes, true, self::MAX_EDGE_BADGE);
    }

    /**
     * Procesa una foto de perfil/portada del receptor. Solo PNG/JPG (sin SVG,
     * que no aplica a fotos), límite mayor (5MB). Va a uploads/profiles/.
     *
     * @param array<string,mixed> $file
     */
    public function processProfileImage(array $file): string
    {
        return $this->persist($file, self::PROFILE_DIR, [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
        ], 5 * 1024 * 1024, false, self::MAX_EDGE_PROFILE);
    }

    /**
     * Procesa el logo de una empresa (PNG/JPG/SVG sanitizado). Va a uploads/logos/.
     *
     * @param array<string,mixed> $file
     */
    public function processLogo(array $file): string
    {
        return $this->persist($file, self::LOGO_DIR, [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/svg+xml' => 'svg',
        ], $this->maxBytes, true, self::MAX_EDGE_LOGO);
    }

    /**
     * Guarda una imagen de insignia generada por la app.
     *
     * Hace falta un camino propio porque `persist()` exige `is_uploaded_file()`
     * y `move_uploaded_file()`, que fallan por diseño con cualquier archivo que
     * no venga de un POST HTTP.
     *
     * Con `$reuse` sobreescribe ese archivo en lugar de crear uno nuevo: los
     * correos ya enviados embeben la URL absoluta de la imagen, así que cambiar
     * el nombre al reeditar un diseño los rompe en la bandeja de entrada de
     * quien ya la recibió.
     *
     * @param string  $webpBytes Bytes de la imagen ya renderizada.
     * @param ?string $reuse     Nombre existente a sobreescribir, si aplica.
     */
    public function storeGeneratedBadge(string $webpBytes, ?string $reuse = null): string
    {
        if (@getimagesizefromstring($webpBytes) === false) {
            throw new RuntimeException('La imagen generada no es válida');
        }

        $dir = self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de uploads');
        }

        // Solo se reutiliza un .webp: si el nombre previo era de una subida en
        // otro formato, se genera uno nuevo y el llamador borra el anterior.
        $name = ($reuse !== null && strtolower(pathinfo($reuse, PATHINFO_EXTENSION)) === 'webp')
            ? basename($reuse)
            : bin2hex(random_bytes(16)) . '.webp';

        // Mismo orden seguro que ImageOptimizerService: escribir aparte,
        // verificar, y recién entonces ocupar el nombre definitivo.
        $tmp = $dir . $name . '.tmp';
        if (file_put_contents($tmp, $webpBytes) === false || @getimagesize($tmp) === false) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar la imagen generada');
        }
        rename($tmp, $dir . $name);
        @chmod($dir . $name, 0644);

        return $name;
    }

    /**
     * Guarda una insignia generada en SVG.
     *
     * Con `$reuse` sobreescribe ese archivo: los correos ya enviados embeben la
     * URL de la imagen, así que cambiar el nombre al reeditar los rompe.
     */
    public function storeGeneratedSvg(string $svg, ?string $reuse = null): string
    {
        if (!str_contains($svg, '<svg')) {
            throw new RuntimeException('El diseño generado no es un SVG válido');
        }

        $dir = self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de uploads');
        }

        $name = ($reuse !== null && strtolower(pathinfo($reuse, PATHINFO_EXTENSION)) === 'svg')
            ? basename($reuse)
            : bin2hex(random_bytes(16)) . '.svg';

        $tmp = $dir . $name . '.tmp';
        if (file_put_contents($tmp, $svg) === false) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar el diseño');
        }
        rename($tmp, $dir . $name);
        @chmod($dir . $name, 0644);
        // Lo generamos nosotros, pero pasa igual por el saneado: es el mismo
        // archivo que después se sirve al público.
        $this->sanitizeSvg($dir . $name);

        return $name;
    }

    public function delete(string $filename): void
    {
        // Evitar path traversal: solo el basename.
        $path = self::UPLOAD_DIR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteLogo(string $filename): void
    {
        $path = self::LOGO_DIR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteProfile(string $filename): void
    {
        $path = self::PROFILE_DIR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Valida (tamaño + MIME real con finfo) y guarda un archivo subido en $dir
     * con nombre aleatorio y permisos públicos (0644). Devuelve el nombre final.
     *
     * @param array<string,mixed>  $file
     * @param array<string,string> $extByMime  MIME permitido => extensión
     */
    private function persist(array $file, string $dir, array $extByMime, int $maxBytes, bool $sanitizeSvg, int $maxEdge = 0): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Error al subir la imagen');
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('Archivo de imagen inválido');
        }
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new InvalidArgumentException('Imagen demasiado grande (máx. ' . intdiv($maxBytes, 1048576) . 'MB)');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!isset($extByMime[$mime])) {
            throw new InvalidArgumentException('Tipo de archivo no permitido (' . implode(', ', array_values($extByMime)) . ')');
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de uploads');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extByMime[$mime];
        $dest     = $dir . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new RuntimeException('Error al guardar la imagen');
        }

        // SVG: sanitizar tras mover (ya en disco bajo nuestro control).
        // PNG/JPG: convertir a WebP (menor peso). Si falla, se conserva el original.
        if ($sanitizeSvg && $mime === 'image/svg+xml') {
            $this->sanitizeSvg($dest);
        } elseif ($mime === 'image/png' || $mime === 'image/jpeg') {
            $filename = $this->toWebp($dest, $mime, $maxEdge) ?? $filename;
        }

        // 0644: imágenes públicas; en cPanel el contenido estático lo sirve otro user.
        @chmod($dir . $filename, 0644);
        return $filename;
    }

    /**
     * Convierte un PNG/JPG ya en disco a WebP, borra el original y devuelve el
     * nuevo nombre. Devuelve null si GD no soporta WebP o la imagen es inválida
     * (en ese caso el llamador conserva el archivo original).
     */
    private function toWebp(string $srcPath, string $mime, int $maxEdge = 0): ?string
    {
        if (!function_exists('imagewebp')) {
            return null; // GD compilado sin soporte WebP.
        }
        $img = $mime === 'image/png'
            ? @imagecreatefrompng($srcPath)
            : @imagecreatefromjpeg($srcPath);
        if (!$img instanceof \GdImage) {
            return null;
        }
        // Preservar transparencia (PNG con canal alfa).
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        if ($maxEdge > 0) {
            $img = self::downscale($img, $maxEdge);
        }

        $webpPath = preg_replace('/\.\w+$/', '.webp', $srcPath);
        $quality  = max(1, min(100, (int) config('upload.webp_quality', 82)));
        $ok       = imagewebp($img, $webpPath, $quality);
        imagedestroy($img);

        if (!$ok) {
            @unlink($webpPath);
            return null;
        }
        @unlink($srcPath); // Original ya convertido.
        return basename($webpPath);
    }

    /**
     * Reduce la imagen para que su lado mayor no exceda $maxEdge, conservando
     * proporción y transparencia. Si ya entra, devuelve la misma imagen sin
     * tocarla: nunca se amplía, porque agrandar solo agrega peso y borrosidad.
     *
     * Público y estático para que el script de mantenimiento que normaliza las
     * imágenes ya subidas use exactamente este camino y no una copia que pueda
     * divergir.
     */
    public static function downscale(\GdImage $img, int $maxEdge): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= $maxEdge || $long === 0) {
            return $img;
        }

        $ratio = $maxEdge / $long;
        $nw    = max(1, (int) round($w * $ratio));
        $nh    = max(1, (int) round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $dst;
    }

    /**
     * Procesa la plantilla de certificado (imagen). Solo PNG/JPG (sin SVG,
     * porque GD la rasteriza), límite mayor (8MB), en uploads/certificates/.
     *
     * @param array<string,mixed> $file
     */
    public function processCertificateUpload(array $file): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK
            || !isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('Error al subir la plantilla');
        }
        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('La plantilla supera 8MB');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file((string) $file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('La plantilla debe ser PNG o JPG');
        }

        $dir = self::CERT_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de certificados');
        }

        $ext      = $mime === 'image/png' ? 'png' : 'jpg';
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = $dir . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new RuntimeException('Error al guardar la plantilla');
        }
        @chmod($dest, 0644);
        return $filename;
    }

    public function deleteCertificate(string $filename): void
    {
        $path = self::CERT_DIR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Sanitiza un SVG eliminando scripts, handlers on* y URIs javascript:.
     */
    /** Elementos SVG de presentación permitidos (allowlist). Todo lo demás se elimina. */
    private const SVG_ALLOWED_TAGS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'symbol', 'marker', 'filter', 'fegaussianblur',
        'feoffset', 'feblend', 'fecolormatrix', 'feflood', 'fecomposite', 'femerge', 'femergenode',
    ];

    /**
     * Sanitiza un SVG con allowlist basado en DOM (no regex): parsea el XML,
     * elimina todo elemento fuera de la allowlist y todo atributo peligroso
     * (handlers on*, href/xlink:href no internos, valores javascript:). Regex no
     * parsea XML de forma confiable (entidades, CDATA, namespaces, <use>, <animate>);
     * el DOM sí. Si el archivo no parsea como XML o falta ext-dom, se vacía
     * (fail-closed: no se sirve un SVG que no pudimos sanitizar).
     */
    private function sanitizeSvg(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return;
        }
        if (!class_exists('DOMDocument')) {
            file_put_contents($path, '');
            return;
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        // Sin red ni entidades externas (anti-XXE): libxml no expande externas por
        // defecto y LIBXML_NONET bloquea cualquier fetch.
        $ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok || $doc->documentElement === null) {
            file_put_contents($path, '');
            return;
        }

        $this->scrubSvgNode($doc->documentElement);
        $clean = $doc->saveXML();
        file_put_contents($path, $clean !== false ? $clean : '');
    }

    /**
     * Recorre un nodo SVG (recursivo) eliminando elementos fuera de la allowlist
     * y atributos peligrosos.
     */
    private function scrubSvgNode(\DOMElement $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->localName ?? $child->nodeName);
            if (!in_array($tag, self::SVG_ALLOWED_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }
            $this->scrubSvgNode($child);
        }

        if ($node->hasAttributes()) {
            foreach (iterator_to_array($node->attributes) as $attr) {
                $name    = strtolower($attr->nodeName);
                $val     = trim((string) $attr->nodeValue);
                $isEvent = str_starts_with($name, 'on');
                $isHref  = ($name === 'href' || str_ends_with($name, ':href'));
                $badHref = $isHref && !str_starts_with($val, '#');
                $hasJs   = stripos($val, 'javascript:') !== false;
                if ($isEvent || $badHref || $hasJs) {
                    $node->removeAttributeNode($attr);
                }
            }
        }
    }
}
