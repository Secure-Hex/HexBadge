<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;
use HexBadge\Models\Earner;
use HexBadge\Models\IssuedBadge;

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

    /** @var array<string,array<string,mixed>|null> Templates por UUID (null = no existe). */
    private array $templateCache = [];

    public function __construct()
    {
        $this->badges    = new BadgeService();
        $this->validator = new Validator();
    }

    /**
     * Índices de columna del encabezado. Consume la primera fila del handle.
     * Acepta cualquier orden y nombres en inglés o español; la columna del
     * template es opcional (si falta se usa el elegido en el formulario).
     *
     * @param resource $handle
     *
     * @return array{first:?int,last:?int,email:?int,locale:?int,tpl:?int}
     */
    private static function columnMap($handle): array
    {
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

        return [
            'first'  => $find(['first_name', 'nombre', 'firstname', 'first']),
            'last'   => $find(['last_name', 'apellido', 'lastname', 'last']),
            'email'  => $find(['email', 'correo', 'e-mail', 'mail']),
            'locale' => $find(['locale', 'idioma']),
            'tpl'    => $find(['badge_template_id', 'template_id', 'template', 'badge', 'uuid']),
        ];
    }

    /**
     * Valor crudo de una celda, sin validar.
     *
     * @param array<int,mixed> $row
     */
    private static function cell(array $row, ?int $idx): string
    {
        return ($idx !== null && isset($row[$idx])) ? trim((string) $row[$idx]) : '';
    }

    private function template(string $uuid): ?array
    {
        if (!array_key_exists($uuid, $this->templateCache)) {
            $this->templateCache[$uuid] = BadgeTemplate::findByUuid($uuid);
        }
        return $this->templateCache[$uuid];
    }

    /**
     * Valida una fila y devuelve sus campos normalizados. Lanza si es inválida.
     * Lo usan por igual la previsualización y la emisión, para que lo que se
     * muestra antes de confirmar sea exactamente lo que se va a procesar.
     *
     * @param array<int,mixed>                                        $row
     * @param array{first:?int,last:?int,email:?int,locale:?int,tpl:?int} $cols
     *
     * @return array{template_uuid:string,first_name:string,last_name:string,email:string,locale:string}
     */
    private function parseRow(array $row, array $cols, string $templateUuid, ?int $allowedCompanyId): array
    {
        $rawTpl  = self::cell($row, $cols['tpl']);
        $tplUuid = $rawTpl !== '' ? $this->validator->uuid($rawTpl) : $templateUuid;

        // Aislamiento por empresa: una fila no puede emitir con un template de
        // otra empresa (el del formulario ya fue validado).
        if ($tplUuid !== $templateUuid) {
            $t = $this->template($tplUuid);
            $companyId = $t === null ? false : (isset($t['company_id']) ? (int) $t['company_id'] : null);
            if ($companyId !== $allowedCompanyId) {
                throw new \RuntimeException('Template fuera de tu empresa');
            }
        }

        return [
            'template_uuid' => $tplUuid,
            'first_name'    => $this->validator->name(self::cell($row, $cols['first'])),
            'last_name'     => $this->validator->name(self::cell($row, $cols['last'])),
            'email'         => $this->validator->email(self::cell($row, $cols['email'])),
            'locale'        => self::cell($row, $cols['locale']) !== ''
                ? $this->validator->locale(self::cell($row, $cols['locale']))
                : 'es',
        ];
    }

    /**
     * Analiza el CSV sin emitir nada: no crea earners, badges ni envía correos.
     * Alimenta la pantalla de revisión previa a confirmar el lote.
     *
     * @return array{
     *     total:int,
     *     valid:int,
     *     duplicates:int,
     *     errors:array<int,array{line:int,email:string,error:string}>,
     *     sample:array<int,array{line:int,name:string,email:string,status:string}>
     * }
     */
    public function preview(string $csvPath, string $templateUuid, ?int $allowedCompanyId = null, int $sampleSize = 10): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el CSV');
        }

        $cols   = self::columnMap($handle);
        $total  = 0;
        $valid  = 0;
        $dupes  = 0;
        $errors = [];
        $sample = [];
        $line   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            $total++;

            try {
                $data   = $this->parseRow($row, $cols, $templateUuid, $allowedCompanyId);
                $status = $this->duplicateStatus($data['template_uuid'], $data['email']);
                if ($status === 'duplicate') {
                    $dupes++;
                } else {
                    $valid++;
                }
                if (count($sample) < $sampleSize) {
                    $sample[] = [
                        'line'   => $line,
                        'name'   => trim($data['first_name'] . ' ' . $data['last_name']),
                        'email'  => $data['email'],
                        'status' => $status,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'email' => self::cell($row, $cols['email']), 'error' => $e->getMessage()];
                if (count($sample) < $sampleSize) {
                    $sample[] = [
                        'line'   => $line,
                        'name'   => trim(self::cell($row, $cols['first']) . ' ' . self::cell($row, $cols['last'])),
                        'email'  => self::cell($row, $cols['email']),
                        'status' => 'error',
                    ];
                }
            }
        }

        fclose($handle);

        return [
            'total'      => $total,
            'valid'      => $valid,
            'duplicates' => $dupes,
            'errors'     => $errors,
            'sample'     => $sample,
        ];
    }

    /**
     * ¿Esta persona ya tiene este badge activo? Consulta de solo lectura: a
     * diferencia de la emisión, NO crea el earner si todavía no existe.
     */
    private function duplicateStatus(string $templateUuid, string $email): string
    {
        $template = $this->template($templateUuid);
        if ($template === null) {
            return 'nuevo';
        }
        $earner = Earner::findByEmail($email);
        if ($earner === null) {
            return 'nuevo';
        }

        return IssuedBadge::hasActiveDuplicate((int) $template['id'], (int) $earner['id'])
            ? 'duplicate'
            : 'nuevo';
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

        $cols = self::columnMap($handle);

        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            $total++;

            try {
                $data  = $this->parseRow($row, $cols, $templateUuid, $allowedCompanyId);
                $email = $data['email'];

                $result = $this->badges->issue(
                    $data['template_uuid'],
                    $email,
                    $data['first_name'],
                    $data['last_name'],
                    $userId,
                    'csv',
                    $data['locale']
                );

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
                $errorRows[] = ['line' => $line, 'email' => self::cell($row, $cols['email']), 'error' => $e->getMessage()];
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
