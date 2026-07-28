<?php

declare(strict_types=1);

namespace HexBadge\Services;

use GdImage;
use HexBadge\Core\Database;

/**
 * Normaliza las imágenes ya subidas: las reduce al lado máximo que la interfaz
 * usa y las convierte a WebP.
 *
 * Las insignias se guardaban en su tamaño original —hasta 1254x1254 y 1,6 MB—
 * y se muestran a 112px en la grilla del perfil y a 224px en la vitrina de la
 * credencial, así que un perfil con siete insignias descargaba más de 5 MB.
 *
 * Las plantillas de diploma quedan fuera a propósito: se imprimen en un PDF y
 * necesitan su resolución original.
 *
 * Trabaja por lotes con tope de tiempo porque en un hosting compartido la
 * petición se corta sola. Es idempotente: lo ya normalizado se saltea, así que
 * se puede repetir hasta que no quede nada pendiente.
 */
final class ImageOptimizerService
{
    /** @var array<int,array{dir:string,maxEdge:int,table:?string,column:?string}> */
    private const TARGETS = [
        ['dir' => 'uploads/badges/',   'maxEdge' => 512,  'table' => 'badge_templates', 'column' => 'image_filename'],
        ['dir' => 'uploads/profiles/', 'maxEdge' => 1280, 'table' => null,              'column' => null],
        ['dir' => 'uploads/logos/',    'maxEdge' => 600,  'table' => 'companies',       'column' => 'logo_filename'],
    ];

    private string $base;
    private int $quality;

    public function __construct()
    {
        $this->base    = BASE_PATH . '/apps/earner/public/';
        $this->quality = max(1, min(100, (int) config('upload.webp_quality', 82)));
    }

    /**
     * Qué hay para hacer, sin tocar nada.
     *
     * @return array{pending:int,done:int,bytes:int,items:array<int,array{name:string,dir:string,w:int,h:int,bytes:int}>}
     */
    public function inspect(): array
    {
        $pending = 0;
        $done    = 0;
        $bytes   = 0;
        $items   = [];

        foreach (self::TARGETS as $t) {
            foreach ($this->files($t['dir']) as $path) {
                $state = $this->classify($path, $t['maxEdge']);
                if ($state === null) {
                    continue;
                }
                if ($state['normalized']) {
                    $done++;
                    continue;
                }
                $pending++;
                $bytes += $state['bytes'];
                $items[] = [
                    'name'  => basename($path),
                    'dir'   => $t['dir'],
                    'w'     => $state['w'],
                    'h'     => $state['h'],
                    'bytes' => $state['bytes'],
                ];
            }
        }

        return ['pending' => $pending, 'done' => $done, 'bytes' => $bytes, 'items' => $items];
    }

    /**
     * Procesa hasta agotar el presupuesto de tiempo. Devuelve el resultado del
     * lote y cuántas quedan, para poder volver a llamar.
     *
     * @return array{processed:int,failed:int,remaining:int,before:int,after:int,log:array<int,string>}
     */
    public function run(int $timeBudgetSeconds = 20): array
    {
        $start     = microtime(true);
        $processed = 0;
        $failed    = 0;
        $before    = 0;
        $after     = 0;
        $log       = [];
        $agotado   = false;

        foreach (self::TARGETS as $t) {
            foreach ($this->files($t['dir']) as $path) {
                if ($agotado) {
                    break 2;
                }
                $state = $this->classify($path, $t['maxEdge']);
                if ($state === null || $state['normalized']) {
                    continue;
                }

                $res = $this->convert($path, $t['maxEdge'], $t['table'], $t['column']);
                if ($res === null) {
                    $failed++;
                    $log[] = 'No se pudo convertir ' . basename($path);
                } else {
                    $processed++;
                    $before += $res['before'];
                    $after  += $res['after'];
                    $log[]  = sprintf(
                        '%s (%dx%d, %s KB) → %s KB',
                        basename($path),
                        $state['w'],
                        $state['h'],
                        number_format($res['before'] / 1024),
                        number_format($res['after'] / 1024)
                    );
                }

                if ((microtime(true) - $start) > $timeBudgetSeconds) {
                    $agotado = true;
                }
            }
        }

        return [
            'processed' => $processed,
            'failed'    => $failed,
            'remaining' => $this->inspect()['pending'],
            'before'    => $before,
            'after'     => $after,
            'log'       => $log,
        ];
    }

    /** @return array<int,string> */
    private function files(string $relDir): array
    {
        $dir = $this->base . $relDir;
        if (!is_dir($dir)) {
            return [];
        }
        return array_values(array_filter(glob($dir . '*') ?: [], 'is_file'));
    }

    /**
     * @return array{normalized:bool,w:int,h:int,bytes:int,type:int}|null  null si el archivo no es una imagen tratable
     */
    private function classify(string $path, int $maxEdge): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'svg' || $ext === '' || basename($path) === '.htaccess') {
            return null; // vectorial o control de acceso
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        return [
            'normalized' => $ext === 'webp' && max($info[0], $info[1]) <= $maxEdge,
            'w'          => (int) $info[0],
            'h'          => (int) $info[1],
            'bytes'      => (int) filesize($path),
            'type'       => (int) $info[2],
        ];
    }

    /**
     * Convierte un archivo y actualiza su fila. Escribe el nuevo, comprueba que
     * sea legible, actualiza la base y recién entonces borra el original: una
     * interrupción nunca deja una fila apuntando a un archivo que no existe.
     *
     * @return array{before:int,after:int}|null
     */
    private function convert(string $path, int $maxEdge, ?string $table, ?string $column): ?array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        $before = (int) filesize($path);

        $img = match ((int) $info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => null,
        };
        if (!$img instanceof GdImage) {
            return null;
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $img = ImageService::downscale($img, $maxEdge);

        $dir     = dirname($path) . '/';
        $name    = basename($path);
        $newName = (string) preg_replace('/\.\w+$/', '.webp', $name);
        $tmp     = $dir . $newName . '.tmp';

        $ok = imagewebp($img, $tmp, $this->quality);
        imagedestroy($img);

        if (!$ok || !is_file($tmp) || @getimagesize($tmp) === false) {
            @unlink($tmp);
            return null;
        }

        $newPath = $dir . $newName;
        rename($tmp, $newPath);
        @chmod($newPath, 0644);

        if ($table !== null && $column !== null && $newName !== $name) {
            Database::getInstance()->query(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column),
                [$newName, $name]
            );
        }

        if ($newPath !== $path) {
            @unlink($path);
        }

        return ['before' => $before, 'after' => (int) filesize($newPath)];
    }
}
