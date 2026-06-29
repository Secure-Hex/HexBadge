<?php

/**
 * Reinicia los DATOS OPERATIVOS de HexBadge para empezar de cero, conservando
 * la instalación, los usuarios del panel y la configuración (SMTP, etc.).
 *
 * BORRA: templates, badges emitidos, receptores (earners), jobs de CSV,
 *        invitaciones, API keys, auditoría, rate limits e imágenes subidas.
 * CONSERVA: tabla `users` (tus administradores) y `settings` (SMTP, etc.).
 *
 * Uso:
 *   php scripts/reset_data.php          (pide confirmación escribiendo BORRAR)
 *   php scripts/reset_data.php --force  (sin confirmación; para cron/automático)
 *
 * ⚠️  Hacé un backup de la base de datos ANTES de ejecutarlo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde la línea de comandos.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use HexBadge\Core\Database;

function out(string $m): void { fwrite(STDOUT, $m . "\n"); }

$force = in_array('--force', $argv, true);

$db  = Database::getInstance();
$pdo = $db->pdo();

// Resumen de lo que hay hoy.
$count = static function (string $t) use ($db): int {
    try { return (int) $db->fetchColumn("SELECT COUNT(*) FROM `$t`"); }
    catch (\Throwable) { return 0; }
};

out("=== HexBadge — Reinicio de datos operativos ===\n");
out("Se BORRARÁ:");
out(sprintf("  - badges emitidos: %d", $count('issued_badges')));
out(sprintf("  - templates:       %d", $count('badge_templates')));
out(sprintf("  - receptores:      %d", $count('earners')));
out(sprintf("  - jobs CSV:        %d", $count('bulk_import_jobs')));
out(sprintf("  - invitaciones:    %d", $count('user_invitations')));
out(sprintf("  - API keys:        %d", $count('api_keys')));
out(sprintf("  - auditoría:       %d", $count('audit_logs')));
out("");
out("Se CONSERVA:");
out(sprintf("  - usuarios del panel: %d", $count('users')));
out(sprintf("  - configuración:      %d ajustes", $count('settings')));
out("");

if (!$force) {
    fwrite(STDOUT, "Escribí BORRAR (en mayúsculas) para confirmar: ");
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'BORRAR') {
        out("Cancelado. No se borró nada.");
        exit(0);
    }
}

// Borrado en orden seguro respecto de las claves foráneas.
$tables = [
    'issued_badges',
    'bulk_import_jobs',
    'earners',
    'badge_templates',
    'api_keys',
    'user_invitations',
    'audit_logs',
    'rate_limit_attempts',
];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    try {
        $pdo->exec("DELETE FROM `$t`");
        $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
        out("  vaciada: $t");
    } catch (\Throwable $e) {
        out("  (omitida $t: " . $e->getMessage() . ")");
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Borrar imágenes de badges subidas (en el docroot público).
$dir = BASE_PATH . '/apps/earner/public/uploads/badges/';
$removed = 0;
if (is_dir($dir)) {
    foreach (glob($dir . '*.{png,jpg,jpeg,svg,gif,webp}', GLOB_BRACE) ?: [] as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }
}
out("  imágenes de badges eliminadas: $removed");

out("\n✅ Datos operativos reiniciados. Tu cuenta admin y la configuración siguen intactas.");
exit(0);
