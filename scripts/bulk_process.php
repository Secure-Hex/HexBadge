<?php

/**
 * Worker CLI de emisión masiva (CLAUDE.md §6.3).
 *
 * Procesa los jobs en estado 'queued' (lotes mayores a 100 filas). Para
 * lotes pequeños el procesamiento es síncrono en la propia request.
 *
 * Uso (idealmente vía cron):  php scripts/bulk_process.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use HexBadge\Core\Database;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Services\CsvImportService;

$db   = Database::getInstance();
$jobs = $db->fetchAll("SELECT * FROM bulk_import_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 10");

if ($jobs === []) {
    fwrite(STDOUT, "No hay jobs en cola.\n");
    exit(0);
}

$importer = new CsvImportService();

foreach ($jobs as $job) {
    $meta    = json_decode((string) ($job['errors_json'] ?? '{}'), true);
    $csvPath = is_array($meta) ? ($meta['_csv_path'] ?? null) : null;

    if (!is_string($csvPath) || !is_file($csvPath)) {
        $db->update('bulk_import_jobs', ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $job['id']]);
        fwrite(STDERR, "Job {$job['uuid']}: CSV no encontrado.\n");
        continue;
    }

    $template = BadgeTemplate::find((int) $job['template_id']);
    if ($template === null) {
        $db->update('bulk_import_jobs', ['status' => 'failed'], 'id = ?', [(int) $job['id']]);
        continue;
    }

    fwrite(STDOUT, "Procesando job {$job['uuid']}...\n");
    $summary = $importer->process((int) $job['id'], $csvPath, (string) $template['uuid'], (int) $job['user_id']);
    @unlink($csvPath);

    fwrite(STDOUT, sprintf(
        "  -> total=%d ok=%d errores=%d omitidos=%d\n",
        $summary['total'],
        $summary['success'],
        $summary['errors'],
        $summary['skipped']
    ));
}

fwrite(STDOUT, "Listo.\n");
exit(0);
