<?php

declare(strict_types=1);

/**
 * Normaliza las imágenes ya subidas: las reduce al lado máximo que la interfaz
 * usa y las convierte a WebP.
 *
 * Las insignias se guardaban en su tamaño original —hasta 1254x1254 y 1,6 MB—
 * y se muestran a 112px en la grilla del perfil y a 224px en la vitrina de la
 * credencial. Un perfil con siete insignias descargaba más de 5 MB.
 *
 * Las plantillas de diploma quedan fuera a propósito: se imprimen en un PDF y
 * necesitan su resolución original.
 *
 * Uso:
 *   php scripts/optimize_images.php --dry-run   # informe, no escribe nada
 *   php scripts/optimize_images.php             # aplica
 *
 * Es idempotente: una imagen ya normalizada se saltea. Por cada archivo escribe
 * el nuevo, verifica que sea legible, actualiza la base y recién entonces borra
 * el original, de modo que una interrupción nunca deja una fila apuntando a un
 * archivo que no existe.
 */

require __DIR__ . '/../src/bootstrap.php';

use HexBadge\Core\Database;
use HexBadge\Services\ImageService;

$dryRun = in_array('--dry-run', $argv, true);
$quality = max(1, min(100, (int) config('upload.webp_quality', 82)));

/** Directorios a normalizar: [ruta, lado máximo, tabla, columna]. */
$targets = [
    ['uploads/badges/',   512,  'badge_templates', 'image_filename'],
    ['uploads/profiles/', 1280, null,              null],   // avatar/portada: el nombre no cambia de fila
    ['uploads/logos/',    600,  'companies',       'logo_filename'],
];

$base = BASE_PATH . '/apps/earner/public/';
$db   = Database::getInstance();

$totalBefore = 0;
$totalAfter  = 0;
$touched     = 0;
$skipped     = 0;
$failed      = 0;

foreach ($targets as [$rel, $maxEdge, $table, $column]) {
    $dir = $base . $rel;
    if (!is_dir($dir)) {
        continue;
    }
    echo "\n== $rel (lado máximo {$maxEdge}px) ==\n";

    foreach (glob($dir . '*') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $name = basename($path);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'svg' || $ext === 'htaccess' || $name === '.htaccess') {
            continue; // vectorial o control de acceso: no se toca
        }

        $sizeBefore = (int) filesize($path);
        $info       = @getimagesize($path);
        if ($info === false) {
            echo "  ? $name — no se pudo leer, se deja como está\n";
            $failed++;
            continue;
        }
        [$w, $h] = $info;

        // Ya normalizada: WebP y dentro del lado máximo.
        if ($ext === 'webp' && max($w, $h) <= $maxEdge) {
            $skipped++;
            $totalBefore += $sizeBefore;
            $totalAfter  += $sizeBefore;
            continue;
        }

        $totalBefore += $sizeBefore;

        if ($dryRun) {
            echo sprintf("  · %s  %dx%d  %s KB → se reduciría\n", $name, $w, $h, number_format($sizeBefore / 1024, 0));
            $touched++;
            continue;
        }

        $img = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => null,
        };
        if (!$img instanceof GdImage) {
            echo "  ? $name — formato no soportado por GD\n";
            $failed++;
            $totalAfter += $sizeBefore;
            continue;
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $img = ImageService::downscale($img, $maxEdge);

        $newName = preg_replace('/\.\w+$/', '.webp', $name);
        $tmp     = $dir . $newName . '.tmp';
        $ok      = imagewebp($img, $tmp, $quality);
        imagedestroy($img);

        if (!$ok || !is_file($tmp) || @getimagesize($tmp) === false) {
            @unlink($tmp);
            echo "  ✗ $name — falló la conversión, se conserva el original\n";
            $failed++;
            $totalAfter += $sizeBefore;
            continue;
        }

        $newPath = $dir . $newName;
        rename($tmp, $newPath);
        @chmod($newPath, 0644);

        // La fila se actualiza recién con el archivo nuevo ya en disco.
        if ($table !== null && $newName !== $name) {
            $db->query(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column),
                [$newName, $name]
            );
        }

        if ($newPath !== $path) {
            @unlink($path);
        }

        $sizeAfter = (int) filesize($newPath);
        $totalAfter += $sizeAfter;
        $touched++;
        echo sprintf(
            "  ✓ %s  %dx%d %s KB → %s  %s KB  (-%d%%)\n",
            $name, $w, $h, number_format($sizeBefore / 1024, 0),
            $newName, number_format($sizeAfter / 1024, 0),
            $sizeBefore > 0 ? (int) round(100 - ($sizeAfter / $sizeBefore * 100)) : 0
        );
    }
}

echo "\n";
echo $dryRun ? "SIMULACRO (no se escribió nada)\n" : "APLICADO\n";
echo sprintf(
    "procesadas: %d · ya estaban bien: %d · con problemas: %d\n",
    $touched, $skipped, $failed
);
if ($dryRun) {
    // En simulacro no hay tamaño final que medir: informar solo lo que hay hoy.
    echo sprintf("peso actual de lo que se tocaría: %s MB\n", number_format($totalBefore / 1048576, 2));
} else {
    echo sprintf(
        "peso total: %s MB → %s MB  (-%d%%)\n",
        number_format($totalBefore / 1048576, 2),
        number_format($totalAfter / 1048576, 2),
        $totalBefore > 0 ? (int) round(100 - ($totalAfter / $totalBefore * 100)) : 0
    );
}
