<?php
/**
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $invitations
 * @var array<int,string>              $errors
 * @var array<int,array<string,mixed>> $companies
 * @var int|null                       $companyFilter
 * @var bool                           $canInviteSuperadmin
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\View;
$showCompany = count($companies) > 1;
?>
<h1>Usuarios</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<section>
    <h2>Invitar usuario</h2>
    <form method="POST" action="/admin/users" style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;max-width:760px">
        <?= CSRF::field() ?>
        <div style="flex:2;min-width:180px">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" maxlength="255" required>
        </div>
        <div style="flex:1">
            <label for="role">Rol</label>
            <select id="role" name="role">
                <option value="issuer">Issuer</option>
                <option value="admin">Admin</option>
                <?php if ($canInviteSuperadmin): ?><option value="superadmin">Superadmin</option><?php endif; ?>
            </select>
        </div>
        <?php if ($showCompany): ?>
        <div style="flex:1;min-width:160px">
            <label for="company_id">Empresa</label>
            <select id="company_id" name="company_id">
                <option value="">—</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Enviar invitación</button>
    </form>
    <p class="muted" style="font-size:.85rem">La persona recibe un email con un enlace para definir su contraseña (válido 7 días).<?php if ($showCompany): ?> El rol <strong>Superadmin</strong> es global (ignora la empresa).<?php endif; ?></p>
</section>

<?php if ($showCompany): ?>
    <form method="GET" action="/admin/users" style="display:flex;gap:.6rem;align-items:end;margin:1rem 0;max-width:360px">
        <?= View::renderPartial('layout/company_filter', ['companies' => $companies, 'selected' => $companyFilter]) ?>
        <button type="submit" class="btn">Filtrar</button>
        <?php if ($companyFilter !== null): ?><a class="btn btn-sm" href="/admin/users">Quitar filtros</a><?php endif; ?>
    </form>
<?php endif; ?>

<section>
    <h2>Usuarios activos</h2>
    <table class="table">
        <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><?php if ($showCompany): ?><th>Empresa</th><?php endif; ?><th>Activo</th><th>Último login</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e((string) $u['name']) ?></td>
                <td class="muted"><?= e((string) $u['email']) ?></td>
                <td><?= e((string) $u['role']) ?></td>
                <?php if ($showCompany): ?><td class="muted"><?= e((string) ($u['company_name'] ?? ($u['role'] === 'superadmin' ? 'Global' : '—'))) ?></td><?php endif; ?>
                <td><?= ((int) $u['is_active'] === 1) ? 'Sí' : 'No' ?></td>
                <td class="muted"><?= e((string) ($u['last_login_at'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php if (!empty($invitations)): ?>
<section>
    <h2>Invitaciones</h2>
    <table class="table">
        <thead><tr><th>Email</th><th>Rol</th><?php if ($showCompany): ?><th>Empresa</th><?php endif; ?><th>Estado</th><th>Invitó</th><th>Expira</th></tr></thead>
        <tbody>
        <?php foreach ($invitations as $i): ?>
            <tr>
                <td><?= e((string) $i['email']) ?></td>
                <td><?= e((string) $i['role']) ?></td>
                <?php if ($showCompany): ?><td class="muted"><?= e((string) ($i['company_name'] ?? ($i['role'] === 'superadmin' ? 'Global' : '—'))) ?></td><?php endif; ?>
                <td>
                    <?php if ($i['accepted_at'] !== null): ?>
                        <span class="badge-status status-accepted">Aceptada</span>
                    <?php elseif (strtotime((string) $i['expires_at']) < time()): ?>
                        <span class="badge-status status-revoked">Expirada</span>
                    <?php else: ?>
                        <span class="badge-status status-pending">Pendiente</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e((string) ($i['invited_by_name'] ?? '—')) ?></td>
                <td class="muted"><?= e((string) $i['expires_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
