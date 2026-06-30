<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Logger;
use HexBadge\Core\RateLimiter;
use HexBadge\Core\Request;
use HexBadge\Core\Response;
use HexBadge\Core\Session;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\BulkImportJob;
use HexBadge\Services\CsvImportService;

/**
 * Emisión masiva por CSV (CLAUDE.md §6.3).
 *
 * Hasta 100 filas se procesan de forma síncrona; lotes mayores quedan en
 * estado 'queued' para el worker CLI (scripts/bulk_process.php).
 */
final class BulkIssueController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB
    private const MAX_ROWS  = 2000;            // se procesa todo en línea (sin cron/worker)
    private const TEMP_DIR  = BASE_PATH . '/storage/temp/';

    public function form(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        return $this->view('issue/bulk_form', [
            'pageTitle' => 'Emisión masiva',
            'templates' => BadgeTemplate::active($this->companyFilter($request)),
            'jobs'      => BulkImportJob::forUser((int) Auth::id()),
            'errors'    => [],
        ]);
    }

    public function upload(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $userId  = (int) Auth::id();
        $limiter = new RateLimiter();
        if (!$limiter->check('user:' . $userId, 'csv', (int) config('rate_limit.csv', 3), 3600)) {
            return $this->fail($request, 'Alcanzaste el límite de subidas por hora. Probá más tarde.');
        }

        $templateUuid = (string) $request->input('template_id', '');
        $template     = BadgeTemplate::findByUuid($templateUuid);
        if ($template === null || $template['state'] !== 'active') {
            return $this->fail($request, 'Elegí un template activo válido.');
        }
        if ($this->assertCompanyAccess(isset($template['company_id']) ? (int) $template['company_id'] : null)) {
            return $this->fail($request, 'No tenés acceso a ese template.');
        }

        $file = $request->file('csv');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->fail($request, 'Subí un archivo CSV.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            return $this->fail($request, 'El CSV supera el máximo de 5MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file((string) $file['tmp_name']);
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
            return $this->fail($request, 'El archivo no parece un CSV válido (' . $mime . ').');
        }

        // Mover a storage/temp (fuera del docroot).
        $this->ensureTempDir();
        $dest = self::TEMP_DIR . bin2hex(random_bytes(12)) . '.csv';
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return $this->fail($request, 'No se pudo procesar el archivo.');
        }
        @chmod($dest, 0640);

        $rows = CsvImportService::countRows($dest);
        if ($rows === 0) {
            @unlink($dest);
            return $this->fail($request, 'El CSV no tiene filas de datos.');
        }
        if ($rows > self::MAX_ROWS) {
            @unlink($dest);
            return $this->fail($request, 'El CSV supera el máximo de ' . self::MAX_ROWS . ' filas. Dividilo en varios archivos más chicos.');
        }

        $jobUuid = uuid4();
        $jobId   = BulkImportJob::create([
            'uuid'          => $jobUuid,
            'user_id'       => $userId,
            'template_id'   => (int) $template['id'],
            'filename_orig' => mb_substr((string) ($file['name'] ?? 'import.csv'), 0, 255),
            'total_rows'    => $rows,
            'status'        => 'queued',
        ]);

        Logger::audit('bulk.uploaded', $userId, 'bulk_import_job', $jobUuid, ['rows' => $rows]);

        // Se procesa todo en línea (incluye el envío de correos en un solo lote).
        // La empresa del template del form acota qué templates puede traer el CSV.
        $allowedCompanyId = isset($template['company_id']) ? (int) $template['company_id'] : null;
        $summary = (new CsvImportService())->process($jobId, $dest, $templateUuid, $userId, $allowedCompanyId);
        @unlink($dest);
        Session::flash('success', sprintf(
            'Procesadas %d filas: %d emitidas, %d omitidas (duplicadas), %d con error.',
            $summary['total'],
            $summary['success'],
            $summary['skipped'],
            $summary['errors']
        ));

        return $this->redirect('/admin/bulk-issue/' . $jobUuid);
    }

    public function show(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $job = BulkImportJob::findByUuid($uuid);
        if ($job === null) {
            return Response::html('<h1>404 — Job no encontrado</h1>', 404);
        }

        // Descarga del CSV de errores.
        if ($request->query('download') === 'errors') {
            return $this->downloadErrors($job);
        }

        $errors = [];
        if (!empty($job['errors_json'])) {
            $decoded = json_decode((string) $job['errors_json'], true);
            if (is_array($decoded)) {
                $errors = array_filter($decoded, static fn ($e) => is_array($e) && isset($e['line']));
            }
        }

        return $this->view('issue/bulk_show', [
            'pageTitle' => 'Job ' . substr($uuid, 0, 8),
            'job'       => $job,
            'errors'    => $errors,
        ]);
    }

    /**
     * @param array<string,mixed> $job
     */
    private function downloadErrors(array $job): Response
    {
        $decoded = json_decode((string) ($job['errors_json'] ?? '[]'), true);
        $rows    = is_array($decoded) ? array_filter($decoded, static fn ($e) => is_array($e) && isset($e['line'])) : [];

        $out = "line,email,error\n";
        foreach ($rows as $r) {
            $out .= sprintf("%d,%s,%s\n", (int) $r['line'], str_replace(',', ' ', (string) ($r['email'] ?? '')), str_replace(',', ' ', (string) ($r['error'] ?? '')));
        }

        return (new Response($out, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="errores_' . substr((string) $job['uuid'], 0, 8) . '.csv"',
        ]));
    }

    private function fail(Request $request, string $message): Response
    {
        return $this->view('issue/bulk_form', [
            'pageTitle' => 'Emisión masiva',
            'templates' => BadgeTemplate::active($this->companyFilter($request)),
            'jobs'      => BulkImportJob::forUser((int) Auth::id()),
            'errors'    => [$message],
        ], 422);
    }

    private function ensureTempDir(): void
    {
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0750, true);
        }
    }
}
