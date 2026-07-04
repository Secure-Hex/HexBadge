<?php
/**
 * @var array<int,array<string,mixed>> $logs
 * @var string                         $action
 */
?>
<h1>Auditoría</h1>
<form method="GET" action="/admin/audit" data-live style="max-width:360px">
    <label for="action">Filtrar por acción</label>
    <div style="display:flex;gap:.5rem">
        <input type="text" id="action" name="action" value="<?= e($action) ?>" placeholder="badge.issued">
        <button type="submit" class="btn">Filtrar</button>
    </div>
</form>

<div data-live-results>
<?php if (empty($logs)): ?>
    <p class="muted" style="margin-top:1rem">Sin registros.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Entidad</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="muted" style="white-space:nowrap"><?= e((string) $l['created_at']) ?></td>
                <td><code><?= e((string) $l['action']) ?></code></td>
                <td><?= e((string) ($l['user_name'] ?? ($l['api_key_id'] ? 'API key #' . $l['api_key_id'] : 'sistema'))) ?></td>
                <td class="muted" style="font-size:.8rem"><?= e((string) ($l['entity_type'] ?? '')) ?> <?= e(substr((string) ($l['entity_id'] ?? ''), 0, 8)) ?></td>
                <td class="muted"><?= e((string) $l['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
