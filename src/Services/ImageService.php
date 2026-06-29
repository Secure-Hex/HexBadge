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
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/svg+xml'];
    // Las imágenes viven en el docroot PÚBLICO (portal de personas), no en admin.
    private const UPLOAD_DIR   = BASE_PATH . '/apps/earner/public/uploads/badges/';
    private const CERT_DIR     = BASE_PATH . '/apps/earner/public/uploads/certificates/';

    private int $maxBytes;

    public function __construct()
    {
        $this->maxBytes = ((int) config('upload.max_size_mb', 2)) * 1024 * 1024;
    }

    /**
     * Procesa un archivo subido y devuelve el nombre final almacenado.
     *
     * @param array<string,mixed> $file Entrada de $_FILES (vía Request::file()).
     */
    public function processUpload(array $file): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Error al subir la imagen');
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('Archivo de imagen inválido');
        }
        if ((int) ($file['size'] ?? 0) > $this->maxBytes) {
            throw new InvalidArgumentException('Imagen demasiado grande (máx. ' . config('upload.max_size_mb', 2) . 'MB)');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file((string) $file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException('Tipo de archivo no permitido (PNG, JPG o SVG)');
        }

        $this->ensureDir();

        $ext      = match ($mime) {
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/svg+xml' => 'svg',
        };
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = self::UPLOAD_DIR . $filename;

        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new RuntimeException('Error al guardar la imagen');
        }

        // Para SVG: sanitizar tras mover (ya en disco bajo nuestro control).
        if ($mime === 'image/svg+xml') {
            $this->sanitizeSvg($dest);
        }

        // 0644: las imágenes de badges son públicas; el servidor web debe poder
        // servirlas (en cPanel el contenido estático lo sirve otro usuario).
        @chmod($dest, 0644);
        return $filename;
    }

    public function delete(string $filename): void
    {
        // Evitar path traversal: solo el basename.
        $filename = basename($filename);
        $path     = self::UPLOAD_DIR . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
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

    private function ensureDir(): void
    {
        if (!is_dir(self::UPLOAD_DIR) && !mkdir(self::UPLOAD_DIR, 0755, true) && !is_dir(self::UPLOAD_DIR)) {
            throw new RuntimeException('No se pudo crear el directorio de uploads');
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
