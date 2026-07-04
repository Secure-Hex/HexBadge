<?php
/**
 * Listado de plantillas de diplomas reutilizables.
 *
 * @var array<int,array<string,mixed>> $diplomas
 * @var bool                           $showCompany
 */
use HexBadge\Core\CSRF;
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <h1>Plantillas de diplomas</h1>
    <a class="btn btn-primary" href="/admin/diploma-templates/new">Nueva plantilla</a>
</div>
<p class="muted">Diseños de diploma reutilizables. Una acreditación puede referenciar una de estas plantillas; si la editás, se actualizan los diplomas de todas las acreditaciones que la usan (referencia viva).</p>

<?php if (empty($diplomas)): ?>
    <p class="muted" style="margin-top:1.5rem">Aún no hay plantillas de diplomas. Creá una para reutilizarla en tus acreditaciones.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Nombre</th><?php if ($showCompany): ?><th>Empresa</th><?php endif; ?><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($diplomas as $d):
            $configured = !empty($d['image_filename']) && !empty($d['config']); ?>
            <tr>
                <td><strong><?= e((string) $d['name']) ?></strong></td>
                <?php if ($showCompany): ?><td class="muted"><?= e((string) ($d['company_name'] ?? '— global')) ?></td><?php endif; ?>
                <td>
                    <?php if ($configured): ?><span class="badge-status status-accepted">configurada</span>
                    <?php else: ?><span class="badge-status status-pending">falta marcar</span><?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn btn-sm" href="/admin/diploma-templates/<?= e((string) $d['uuid']) ?>/mark"><?= $configured ? 'Reconfigurar' : 'Marcar' ?></a>
                    <a class="btn btn-sm" href="/admin/diploma-templates/<?= e((string) $d['uuid']) ?>/edit">Editar</a>
                    <form method="POST" action="/admin/diploma-templates/<?= e((string) $d['uuid']) ?>/delete" style="display:inline"
                          onsubmit="return confirm('¿Borrar esta plantilla de diploma?')">
                        <?= CSRF::field() ?>
                        <button class="btn btn-sm btn-danger" type="submit">Borrar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
