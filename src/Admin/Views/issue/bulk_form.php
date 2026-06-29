<?php
/**
 * @var array<int,array<string,mixed>> $templates
 * @var array<int,array<string,mixed>> $jobs
 * @var array<int,string>              $errors
 */
use HexBadge\Core\CSRF;
?>
<h1>Emisión masiva (CSV)</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (empty($templates)): ?>
    <div class="alert alert-error">No hay templates activos. Creá uno y activalo.</div>
<?php else: ?>
<form method="POST" action="/admin/bulk-issue" enctype="multipart/form-data" style="max-width:560px">
    <?= CSRF::field() ?>

    <label for="template_id">Template por defecto *</label>
    <select id="template_id" name="template_id" required>
        <option value="">— Elegí un template —</option>
        <?php foreach ($templates as $t): ?>
            <option value="<?= e((string) $t['uuid']) ?>"><?= e((string) $t['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="csv">Archivo CSV * (máx 5MB, hasta 2.000 filas por archivo)</label>
    <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>

    <button type="submit" class="btn btn-primary btn-block">Subir y procesar</button>
</form>

<div class="muted" style="margin-top:1rem;font-size:.85rem">
    <strong>Formato del CSV</strong> (con encabezado en la primera fila). Todas las personas reciben el <strong>template que elegiste arriba</strong>:
    <pre style="background:var(--surface);padding:.75rem;border-radius:6px;overflow:auto">first_name,last_name,email
Juan,Pérez,juan@example.com
Ana,Gómez,ana@example.com</pre>
    <ul style="margin:.5rem 0 0;padding-left:1.1rem">
        <li>Columnas requeridas: <code>first_name</code>, <code>last_name</code>, <code>email</code>. Opcional: <code>locale</code> (es/en).</li>
        <li>El orden de las columnas no importa (se detectan por el nombre del encabezado).</li>
        <li><em>Avanzado:</em> si querés emitir distintos badges en un mismo CSV, agregá una columna <code>badge_template_id</code> con el UUID del template (lo ves en la página de cada template). Si la dejás vacía, usa el de arriba.</li>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($jobs)): ?>
<section>
    <h2>Trabajos recientes</h2>
    <table class="table">
        <thead><tr><th>Archivo</th><th>Template</th><th>Filas</th><th>Éxito</th><th>Errores</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td><a href="/admin/bulk-issue/<?= e((string) $j['uuid']) ?>"><?= e((string) $j['filename_orig']) ?></a></td>
                <td><?= e((string) $j['template_name']) ?></td>
                <td><?= e((string) $j['total_rows']) ?></td>
                <td><?= e((string) $j['success_count']) ?></td>
                <td><?= e((string) $j['error_count']) ?></td>
                <td><span class="badge-status status-<?= $j['status'] === 'done' ? 'accepted' : ($j['status'] === 'failed' ? 'revoked' : 'pending') ?>"><?= e((string) $j['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
