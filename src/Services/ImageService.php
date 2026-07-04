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
        ], $this->maxBytes, true);
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
        ], 5 * 1024 * 1024, false);
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
        ], $this->maxBytes, true);
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
    private function persist(array $file, string $dir, array $extByMime, int $maxBytes, bool $sanitizeSvg): string
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
        if ($sanitizeSvg && $mime === 'image/svg+xml') {
            $this->sanitizeSvg($dest);
        }

        // 0644: imágenes públicas; en cPanel el contenido estático lo sirve otro user.
        @chmod($dest, 0644);
        return $filename;
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
    private function sanitizeSvg(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }
        $content = preg_replace('/<script[\s\S]*?<\/script>/i', '', $content) ?? $content;
        $content = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content) ?? $content;
        $content = preg_replace('/javascript:/i', '', $content) ?? $content;
        $content = preg_replace('/<foreignObject[\s\S]*?<\/foreignObject>/i', '', $content) ?? $content;
        file_put_contents($path, $content);
    }
}
