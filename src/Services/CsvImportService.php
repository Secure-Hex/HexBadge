<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\BulkImportJob;

/**
 * Procesamiento de emisión masiva por CSV (CLAUDE.md §6.3).
 *
 * Formato: badge_template_id,first_name,last_name,email,locale
 * Filas con error se registran y NO abortan el lote; duplicados se omiten.
 */
final class CsvImportService
{
    private BadgeService $badges;
    private Validator $validator;

    public function __construct()
    {
        $this->badges    = new BadgeService();
        $this->validator = new Validator();
    }

    /**
     * Procesa un archivo CSV ya validado y movido a storage/temp.
     * Actualiza el job con conteos y errores. Devuelve resumen.
     *
     * @param ?int $allowedCompanyId Empresa del lote (la del template del
     *        formulario). Toda fila con su propia columna de template debe
     *        pertenecer a esta misma empresa; si no, la fila se cuenta como
     *        error. Evita que un sub-admin emita con un template ajeno
     *        poniendo su UUID en el CSV.
     *
     * @return array{total:int,success:int,errors:int,skipped:int}
     */
    public function process(int $jobId, string $csvPath, string $templateUuid, int $userId, ?int $allowedCompanyId = null): array
    {
        // El envío de correos en línea puede tardar; evitar el timeout de PHP.
        @set_time_limit(0);

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el CSV');
        }

        $db = Database::getInstance();
        $db->update('bulk_import_jobs', ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);

        $success = 0;
        $errors  = 0;
        $skipped = 0;
        $total   = 0;
        $errorRows = [];
        $notifications = [];   // correos a enviar en lote al final (una sola conexión SMTP)

        // Mapear columnas por NOMBRE de encabezado (acepta cualquier orden, y
        // la columna del template es opcional: si no está, se usa el template
        // seleccionado en el formulario).
        $header = fgetcsv($handle);
        $map    = [];
        foreach (is_array($header) ? $header : [] as $i => $name) {
            $key = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $name)));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }
        $find = static function (array $names) use ($map): ?int {
            foreach ($names as $n) {
                if (array_key_exists($n, $map)) {
                    return $map[$n];
                }
            }
            return null;
        };
        $iFirst  = $find(['first_name', 'nombre', 'firstname', 'first']);
        $iLast   = $find(['last_name', 'apellido', 'lastname', 'last']);
        $iEmail  = $find(['email', 'correo', 'e-mail', 'mail']);
        $iLocale = $find(['locale', 'idioma']);
        $iTpl    = $find(['badge_template_id', 'template_id', 'template', 'badge', 'uuid']);

        // Empresa de cada template por UUID, cacheada (la mayoría de filas
        // repiten el mismo template). false = el template no existe.
        $companyOf = static function (string $uuid): int|false|null {
            static $cache = [];
            if (!array_key_exists($uuid, $cache)) {
                $t = BadgeTemplate::findByUuid($uuid);
                $cache[$uuid] = $t === null
                    ? false
                    : (isset($t['company_id']) ? (int) $t['company_id'] : null);
            }
            return $cache[$uuid];
        };

        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || $row === false) {
                continue;
            }
            $total++;

            $cell = static fn (?int $idx): string => ($idx !== null && isset($row[$idx])) ? trim((string) $row[$idx]) : '';

            try {
                $tplUuid   = $cell($iTpl) !== '' ? $this->validator->uuid($cell($iTpl)) : $templateUuid;
                // Aislamiento por empresa: una fila no puede emitir con un
                // template de otra empresa (el del formulario ya fue validado).
                if ($tplUuid !== $templateUuid && $companyOf($tplUuid) !== $allowedCompanyId) {
                    throw new \RuntimeException('Template fuera de tu empresa');
                }
                $firstName = $this->validator->name($cell($iFirst));
                $lastName  = $this->validator->name($cell($iLast));
                $email     = $this->validator->email($cell($iEmail));
                $locale    = $cell($iLocale) !== '' ? $this->validator->locale($cell($iLocale)) : 'es';

                $result = $this->badges->issue($tplUuid, $email, $firstName, $lastName, $userId, 'csv', $locale);

                if ($result['ok']) {
                    $success++;
                    // Armar el correo (mismo de la emisión individual); se envía
                    // todo junto al final, reutilizando una sola conexión SMTP.
                    $msg = $this->badges->buildNotificationMessage((string) $result['badge_uuid'], (string) $result['accept_token']);
                    if ($msg !== null) {
                        $notifications[] = $msg;
                    }
                } elseif ($result['reason'] === 'duplicate') {
                    $skipped++;
                } else {
                    $errors++;
                    $errorRows[] = ['line' => $line, 'email' => $email, 'error' => $result['reason']];
                }
            } catch (\Throwable $e) {
                $errors++;
                $errorRows[] = ['line' => $line, 'email' => $cell($iEmail), 'error' => $e->getMessage()];
            }

            $db->update('bulk_import_jobs', ['processed' => $total], 'id = ?', [$jobId]);
        }

        fclose($handle);

        // Enviar TODAS las notificaciones reutilizando una sola conexión SMTP.
        // Una importación es de un solo template/empresa → su SMTP propio si tiene.
        if ($notifications !== []) {
            $companyId = $notifications[0]['company_id'] ?? null;
            $sent = (new EmailService())->sendMany($notifications, $companyId);
            foreach ($sent as $i => $ok) {
                if ($ok) {
                    $this->badges->markNotificationSent($notifications[$i]['badge_id']);
                }
            }
        }

        $db->update('bulk_import_jobs', [
            'status'        => 'done',
            'total_rows'    => $total,
            'processed'     => $total,
            'success_count' => $success,
            'error_count'   => $errors,
            'errors_json'   => $errorRows === [] ? null : json_encode($errorRows, JSON_UNESCAPED_UNICODE),
            'finished_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);

        return ['total' => $total, 'success' => $success, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * Cuenta filas de datos (sin encabezado) para decidir sync vs async.
     */
    public static function countRows(string $csvPath): int
    {
        $count  = 0;
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            return 0;
        }
        fgetcsv($handle); // encabezado
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }
}
