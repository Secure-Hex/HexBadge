<?php
/**
 * @var array<string,mixed>            $job
 * @var array<int,array<string,mixed>> $errors
 */
$j = $job;
?>
<h1>Emisión masiva — <?= e((string) $j['filename_orig']) ?></h1>
<p><span class="badge-status status-<?= $j['status'] === 'done' ? 'accepted' : ($j['status'] === 'failed' ? 'revoked' : 'pending') ?>"><?= e((string) $j['status']) ?></span></p>

<div class="cards">
    <div class="card"><span class="card-value"><?= e((string) $j['total_rows']) ?></span><span class="card-label">Filas totales</span></div>
    <div class="card"><span class="card-value"><?= e((string) $j['success_count']) ?></span><span class="card-label">Emitidos</span></div>
    <div class="card"><span class="card-value"><?= e((string) $j['error_count']) ?></span><span class="card-label">Errores</span></div>
</div>

<?php if (!empty($errors)): ?>
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2>Errores por fila</h2>
        <a class="btn btn-sm" href="/admin/bulk-issue/<?= e((string) $j['uuid']) ?>?download=errors">Descargar CSV de errores</a>
    </div>
    <table class="table">
        <thead><tr><th>Fila</th><th>Email</th><th>Error</th></tr></thead>
        <tbody>
        <?php foreach ($errors as $err): ?>
            <tr><td><?= e((string) $err['line']) ?></td><td><?= e((string) ($err['email'] ?? '')) ?></td><td><?= e((string) ($err['error'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php elseif ($j['status'] === 'done'): ?>
    <p class="muted">Sin errores. 🎉</p>
<?php elseif ($j['status'] === 'queued'): ?>
    <p class="muted">El lote está en cola. Ejecutá el worker: <code>php scripts/bulk_process.php</code></p>
<?php endif; ?>
