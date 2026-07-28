<?php
/**
 * Revisión previa a una emisión masiva: qué va a pasar exactamente si se
 * confirma. Nada se emitió todavía al llegar acá.
 *
 * @var array<string,mixed> $job       uuid + filename_orig del lote pendiente
 * @var array<string,mixed> $template
 * @var array{total:int,valid:int,duplicates:int,errors:array<int,array{line:int,email:string,error:string}>,sample:array<int,array{line:int,name:string,email:string,status:string}>} $preview
 */
use HexBadge\Core\CSRF;

$valid  = (int) $preview['valid'];
$dupes  = (int) $preview['duplicates'];
$failed = count($preview['errors']);
?>
<h1>Revisar antes de emitir</h1>

<p class="muted">
    Archivo <strong><?= e((string) $job['filename_orig']) ?></strong> ·
    Template <strong><?= e((string) $template['name']) ?></strong> ·
    <?= (int) $preview['total'] ?> filas leídas
</p>

<div class="review-summary">
    <div class="review-stat review-stat-go">
        <strong><?= $valid ?></strong>
        <span><?= $valid === 1 ? 'credencial se emite' : 'credenciales se emiten' ?></span>
    </div>
    <div class="review-stat">
        <strong><?= $dupes ?></strong>
        <span><?= $dupes === 1 ? 'persona ya lo tiene' : 'personas ya lo tienen' ?> (se omiten)</span>
    </div>
    <div class="review-stat<?= $failed > 0 ? ' review-stat-bad' : '' ?>">
        <strong><?= $failed ?></strong>
        <span><?= $failed === 1 ? 'fila con error' : 'filas con error' ?> (se omiten)</span>
    </div>
</div>

<?php if ($valid > 0): ?>
    <div class="alert alert-warn">
        Al confirmar se emiten <strong><?= $valid ?></strong>
        <?= $valid === 1 ? 'credencial permanente' : 'credenciales permanentes' ?>
        y se envía un correo de notificación a cada persona. No se puede deshacer:
        una credencial emitida solo se revoca, y la revocación queda visible en su
        página pública.
    </div>
<?php else: ?>
    <div class="alert alert-error">
        No hay ninguna fila para emitir. Corregí el archivo y subilo de nuevo.
    </div>
<?php endif; ?>

<?php if (!empty($preview['sample'])): ?>
<section>
    <h2>Primeras <?= count($preview['sample']) ?> filas</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Fila</th><th>Persona</th><th>Email</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($preview['sample'] as $row): ?>
                <?php
                [$label, $cls] = match ($row['status']) {
                    'duplicate' => ['Ya lo tiene', 'status-pending'],
                    'error'     => ['Se omite', 'status-revoked'],
                    default     => ['Se emite', 'status-accepted'],
                };
                ?>
                <tr>
                    <td><?= (int) $row['line'] ?></td>
                    <td><?= e((string) $row['name']) ?></td>
                    <td><?= e((string) $row['email']) ?></td>
                    <td><span class="badge-status <?= $cls ?>"><?= $label ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($failed > 0): ?>
<section>
    <h2><?= $failed === 1 ? 'La fila con error' : 'Las filas con error' ?></h2>
    <p class="muted">Se saltean; el resto del lote se emite igual.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Fila</th><th>Email</th><th>Motivo</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($preview['errors'], 0, 20) as $err): ?>
                <tr>
                    <td><?= (int) $err['line'] ?></td>
                    <td><?= e((string) $err['email']) ?></td>
                    <td><?= e((string) $err['error']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($failed > 20): ?>
        <p class="muted">y <?= $failed - 20 ?> más.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="review-actions">
    <?php if ($valid > 0): ?>
        <form method="POST" action="/admin/bulk-issue/<?= e((string) $job['uuid']) ?>/confirm">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-primary"
                    data-confirm="Se van a emitir <?= $valid ?> credenciales permanentes y se enviarán <?= $valid ?> correos. ¿Confirmás?">
                Emitir <?= $valid ?> <?= $valid === 1 ? 'credencial' : 'credenciales' ?> y notificar
            </button>
        </form>
    <?php endif; ?>
    <a class="btn btn-ghost" href="/admin/bulk-issue">Cancelar</a>
</div>
