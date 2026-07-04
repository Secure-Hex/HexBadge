<?php
/**
 * Descarga de diplomas en lote para un template con certificado.
 *
 * @var array<string,mixed>             $template
 * @var array<int,array<string,mixed>>  $badges
 */
use HexBadge\Core\CSRF;

$t      = $template;
$search = $search ?? '';
$base   = '/admin/templates/' . e((string) $t['uuid']) . '/certificates';
?>
<div class="page-head">
    <h1>Descargar diplomas</h1>
    <a class="btn btn-sm" href="/admin/templates/<?= e((string) $t['uuid']) ?>">‹ Volver al template</a>
</div>
<p class="muted"><strong><?= e((string) $t['name']) ?></strong> · <?= count($badges) ?> receptor<?= count($badges) === 1 ? '' : 'es' ?><?= $search !== '' ? ' coinciden' : ' con diploma disponible' ?> (no se incluyen revocados).</p>

<form method="GET" action="<?= $base ?>" style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;margin:1rem 0">
    <div>
        <label for="q">Buscar por nombre o email</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Ej: ana@correo.com o María" data-filter-rows="#certs-table" autocomplete="off">
    </div>
    <button type="submit" class="btn">Buscar</button>
    <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= $base ?>">Limpiar</a><?php endif; ?>
</form>

<?php if (empty($badges)): ?>
    <div class="alert alert-error"><?= $search !== '' ? 'No hay receptores que coincidan con la búsqueda.' : 'Todavía no hay badges emitidos para este template.' ?></div>
<?php else: ?>
<form method="POST" action="/admin/templates/<?= e((string) $t['uuid']) ?>/certificates">
    <?= CSRF::field() ?>

    <fieldset style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin:0 0 1.25rem">
        <legend class="muted" style="padding:0 .4rem">Formato</legend>
        <label style="display:flex;gap:.5rem;align-items:flex-start;margin:.25rem 0">
            <input type="radio" name="format" value="pdf" checked style="width:auto;margin-top:.2rem">
            <span><strong>Un solo PDF</strong> — todos los diplomas, una página cada uno. Ideal para imprimir de una.</span>
        </label>
        <label style="display:flex;gap:.5rem;align-items:flex-start;margin:.25rem 0">
            <input type="radio" name="format" value="zip" style="width:auto;margin-top:.2rem">
            <span><strong>ZIP</strong> — un archivo PDF por persona, comprimidos juntos.</span>
        </label>
    </fieldset>

    <table class="table" id="certs-table">
        <thead>
            <tr><th style="width:2.5rem"></th><th>Receptor</th><th>Email</th><th>Estado</th><th>Emitido</th></tr>
        </thead>
        <tbody>
            <?php foreach ($badges as $b): ?>
                <tr data-search="<?= e((string) $b['earner_name'] . ' ' . (string) $b['earner_email']) ?>">
                    <td><input type="checkbox" name="badges[]" value="<?= e((string) $b['uuid']) ?>" style="width:auto"></td>
                    <td><?= e((string) $b['earner_name']) ?></td>
                    <td class="muted"><?= e((string) $b['earner_email']) ?></td>
                    <td><span class="badge-status status-<?= e((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span></td>
                    <td><?= e((string) $b['issued_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="display:flex;gap:.6rem;margin-top:1.25rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">Descargar seleccionados</button>
        <button type="submit" name="all" value="1" class="btn">Descargar todos</button>
    </div>
</form>
<?php endif; ?>
