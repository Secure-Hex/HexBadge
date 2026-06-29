<?php
/**
 * @var array<int,array<string,mixed>> $companies
 */
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:center">
    <h1>Empresas</h1>
    <a class="btn btn-primary" href="/admin/companies/new">Nueva empresa</a>
</div>
<p class="muted">Cada empresa es un espacio aislado. Sus datos (nombre, URL, email, LinkedIn) son el emisor que heredan todos sus templates y acreditaciones.</p>

<?php if (empty($companies)): ?>
    <p class="muted">No hay empresas todavía.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Nombre</th><th>Email emisor</th><th>LinkedIn Org</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($companies as $c): ?>
            <tr>
                <td><strong><?= e((string) $c['name']) ?></strong></td>
                <td class="muted"><?= e((string) ($c['issuer_email'] ?? '—')) ?></td>
                <td class="muted"><?= e((string) ($c['linkedin_org_id'] ?? '—')) ?></td>
                <td><?= ((int) $c['is_active'] === 1) ? '<span class="badge-status status-accepted">activa</span>' : '<span class="badge-status status-revoked">inactiva</span>' ?></td>
                <td><a href="/admin/companies/<?= e((string) $c['uuid']) ?>/edit">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
