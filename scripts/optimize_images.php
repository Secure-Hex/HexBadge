<?php

declare(strict_types=1);

/**
 * Normaliza las imágenes ya subidas desde la consola.
 *
 * La misma tarea está en el panel, para hostings donde no hay terminal:
 * Configuración → Optimizar imágenes (solo superadmin). Ambas entradas usan
 * ImageOptimizerService, así que no hay dos comportamientos posibles.
 *
 * Uso:
 *   php scripts/optimize_images.php --dry-run   # informe, no escribe nada
 *   php scripts/optimize_images.php             # aplica hasta terminar
 */

require __DIR__ . '/../src/bootstrap.php';

use HexBadge\Services\ImageOptimizerService;

$dryRun = in_array('--dry-run', $argv, true);
$svc    = new ImageOptimizerService();
$report = $svc->inspect();

printf(
    "pendientes: %d (%s MB) · ya optimizadas: %d\n\n",
    $report['pending'],
    number_format($report['bytes'] / 1048576, 2),
    $report['done']
);

foreach ($report['items'] as $it) {
    printf("  · %-40s %dx%d  %s KB\n", $it['name'], $it['w'], $it['h'], number_format($it['bytes'] / 1024));
}

if ($dryRun) {
    echo "\nSIMULACRO: no se escribió nada.\n";
    exit(0);
}

if ($report['pending'] === 0) {
    echo "Nada que hacer.\n";
    exit(0);
}

echo "\n";
$before = 0;
$after  = 0;
$failed = 0;

// Sin límite de tiempo por tanda: en consola no hay petición que se corte.
do {
    $r = $svc->run(3600);
    foreach ($r['log'] as $line) {
        echo '  ' . $line . "\n";
    }
    $before += $r['before'];
    $after  += $r['after'];
    $failed += $r['failed'];
} while ($r['processed'] > 0 && $r['remaining'] > 0);

printf(
    "\nAPLICADO · con problemas: %d\npeso: %s MB → %s MB (-%d%%)\n",
    $failed,
    number_format($before / 1048576, 2),
    number_format($after / 1048576, 2),
    $before > 0 ? (int) round(100 - ($after / $before * 100)) : 0
);
