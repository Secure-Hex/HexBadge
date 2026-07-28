<?php

declare(strict_types=1);

namespace HexBadge\Admin\Controllers;

use HexBadge\Core\Auth;
use HexBadge\Core\Controller;
use HexBadge\Core\Database;
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
 * Son dos pasos: `upload` analiza el archivo y muestra qué va a pasar,
 * `confirm` emite. Emitir es irreversible (credenciales permanentes + correos
 * a terceros), así que nadie debería llegar ahí sin ver antes los números.
 *
 * El lote se procesa de forma síncrona en la propia request (hasta MAX_ROWS
 * filas), incluido el envío de correos en un solo lote SMTP.
 */
final class BulkIssueController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB
    private const MAX_ROWS  = 2000;            // se procesa todo en línea (sin cron/worker)
    private const TEMP_DIR  = BASE_PATH . '/storage/temp/';
    private const STALE_AGE = 86400;           // los CSV sin confirmar se limpian a las 24h

    public function form(Request $request): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->purgeStale();
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
        // El CSV pendiente se guarda con el nombre del job: el paso de
        // confirmación deriva la ruta del UUID, sin exponerla al cliente.
        $pending = self::TEMP_DIR . $jobUuid . '.csv';
        if (!rename($dest, $pending)) {
            @unlink($dest);
            return $this->fail($request, 'No se pudo procesar el archivo.');
        }

        $jobId = BulkImportJob::create([
            'uuid'          => $jobUuid,
            'user_id'       => $userId,
            'template_id'   => (int) $template['id'],
            'filename_orig' => mb_substr((string) ($file['name'] ?? 'import.csv'), 0, 255),
            'total_rows'    => $rows,
            'status'        => 'queued',
        ]);

        Logger::audit('bulk.uploaded', $userId, 'bulk_import_job', $jobUuid, ['rows' => $rows]);

        // Análisis sin efectos: no crea earners, no emite, no manda correos.
        // La empresa del template del form acota qué templates puede traer el CSV.
        $allowedCompanyId = isset($template['company_id']) ? (int) $template['company_id'] : null;
        $preview = (new CsvImportService())->preview($pending, $templateUuid, $allowedCompanyId);

        return $this->view('issue/bulk_review', [
            'pageTitle' => 'Revisar emisión masiva',
            'job'       => ['uuid' => $jobUuid, 'filename_orig' => (string) ($file['name'] ?? 'import.csv')],
            'template'  => $template,
            'preview'   => $preview,
        ]);
    }

    /**
     * Paso 2: emite el lote ya revisado. Solo procesa jobs en estado `queued`,
     * de forma que recargar o reenviar no vuelva a emitir.
     */
    public function confirm(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $this->verifyCsrf($request);

        $job = BulkImportJob::findFullByUuid($uuid);
        if ($job === null) {
            return $this->fail($request, 'Ese lote ya no existe.');
        }
        if ($resp = $this->assertCompanyAccess(isset($job['company_id']) ? (int) $job['company_id'] : null)) {
            return $resp;
        }

        if ($job['status'] !== 'queued') {
            Session::flash('error', 'Ese lote ya fue procesado.');
            return $this->redirect('/admin/bulk-issue/' . (string) $job['uuid']);
        }

        // Ruta derivada del UUID del registro (no del parámetro crudo).
        $csvPath = self::TEMP_DIR . (string) $job['uuid'] . '.csv';
        if (!is_file($csvPath)) {
            Session::flash('error', 'El archivo del lote expiró sin confirmarse. Subilo de nuevo.');
            return $this->redirect('/admin/bulk-issue');
        }

        // Reclamo atómico del job: si otra request ya lo tomó, no se re-emite.
        $claimed = Database::getInstance()->update(
            'bulk_import_jobs',
            ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s')],
            'id = ? AND status = ?',
            [(int) $job['id'], 'queued']
        );
        if ($claimed === 0) {
            Session::flash('error', 'Ese lote ya fue procesado.');
            return $this->redirect('/admin/bulk-issue/' . (string) $job['uuid']);
        }

        $userId = (int) Auth::id();
        Logger::audit('bulk.confirmed', $userId, 'bulk_import_job', (string) $job['uuid'], [
            'rows' => (int) $job['total_rows'],
        ]);

        // Se procesa todo en línea (incluye el envío de correos en un solo lote).
        $allowedCompanyId = isset($job['company_id']) ? (int) $job['company_id'] : null;
        $summary = (new CsvImportService())->process(
            (int) $job['id'],
            $csvPath,
            (string) $job['template_uuid'],
            $userId,
            $allowedCompanyId
        );
        @unlink($csvPath);

        Session::flash('success', sprintf(
            'Procesadas %d filas: %d emitidas, %d omitidas (duplicadas), %d con error.',
            $summary['total'],
            $summary['success'],
            $summary['skipped'],
            $summary['errors']
        ));

        return $this->redirect('/admin/bulk-issue/' . (string) $job['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        if ($r = Auth::requireRole('issuer')) {
            return $r;
        }
        $job = BulkImportJob::findFullByUuid($uuid);
        if ($job === null) {
            return Response::notFound('Ese lote de emisión no existe.');
        }
        // Control de acceso: el job (y los emails de sus destinatarios) solo es
        // visible para la empresa dueña del template, o un superadmin.
        if ($resp = $this->assertCompanyAccess(isset($job['company_id']) ? (int) $job['company_id'] : null)) {
            return $resp;
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

    /**
     * Borra los CSV de lotes que se subieron pero nunca se confirmaron.
     * ponytail: barrido al entrar al formulario, sin cron. Si el volumen de
     * subidas crece, mover a una tarea programada.
     */
    private function purgeStale(): void
    {
        foreach (glob(self::TEMP_DIR . '*.csv') ?: [] as $file) {
            if (is_file($file) && (time() - (int) filemtime($file)) > self::STALE_AGE) {
                @unlink($file);
            }
        }
    }
}
