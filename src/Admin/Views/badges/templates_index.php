<?php
/**
 * @var array<int,array<string,mixed>> $templates
 * @var array<int,array<string,mixed>> $companies
 * @var int|null                       $companyFilter
 */
use HexBadge\Core\View;
$showCompany = count($companies) > 1;
?>
<div style="display:flex;justify-content:space-between;align-items:center">
    <h1>Templates de badges</h1>
    <a class="btn btn-primary" href="/admin/templates/new">+ Nuevo template</a>
</div>

<?php if ($showCompany): ?>
    <form method="GET" action="/admin/templates" style="display:flex;gap:.6rem;align-items:end;margin:1rem 0;max-width:360px">
        <?= View::renderPartial('layout/company_filter', ['companies' => $companies, 'selected' => $companyFilter]) ?>
        <button type="submit" class="btn">Filtrar</button>
        <?php if ($companyFilter !== null): ?><a class="btn btn-sm" href="/admin/templates">Quitar filtros</a><?php endif; ?>
    </form>
<?php endif; ?>

<?php if (empty($templates)): ?>
    <p class="muted">No hay templates todavía. Creá el primero.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th></th><th>Nombre</th><?php if ($showCompany): ?><th>Empresa</th><?php endif; ?><th>Estado</th><th>Emitidos</th><th>Visibilidad</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
            <tr>
                <td><img src="<?= e(badge_image_url((string) $t['image_filename'])) ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:6px"></td>
                <td><a href="/admin/templates/<?= e((string) $t['uuid']) ?>"><?= e((string) $t['name']) ?></a></td>
                <?php if ($showCompany): ?><td class="muted"><?= e((string) ($t['company_name'] ?? '—')) ?></td><?php endif; ?>
                <td><span class="badge-status status-<?= $t['state'] === 'active' ? 'accepted' : ($t['state'] === 'archived' ? 'revoked' : 'pending') ?>"><?= e((string) $t['state']) ?></span></td>
                <td><?= e((string) $t['badges_issued']) ?></td>
                <td><?= ((int) $t['is_public'] === 1) ? 'Público' : 'Privado' ?></td>
                <td><a href="/admin/templates/<?= e((string) $t['uuid']) ?>/edit">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
