<?php
/**
 * @var array<int,array<string,mixed>> $keys
 * @var array<int,string>              $scopes
 * @var string|null                    $newKey
 */
use HexBadge\Core\CSRF;
?>
<h1>API Keys</h1>

<?php if (!empty($newKey)): ?>
    <div class="alert alert-success">
        Tu nueva API key (se muestra una sola vez):<br>
        <code style="display:block;margin-top:.5rem;word-break:break-all;font-size:.95rem"><?= e($newKey) ?></code>
    </div>
<?php endif; ?>

<section>
    <h2>Crear API key</h2>
    <form method="POST" action="/admin/api-keys" style="max-width:560px">
        <?= CSRF::field() ?>
        <label for="name">Nombre descriptivo</label>
        <input type="text" id="name" name="name" maxlength="100" required placeholder="Integración CRM">
        <label>Scopes</label>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;margin:.3rem 0">
            <?php foreach ($scopes as $s): ?>
                <label style="display:flex;align-items:center;gap:.4rem;margin:0">
                    <input type="checkbox" name="scopes[]" value="<?= e($s) ?>" style="width:auto"> <?= e($s) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Generar key</button>
    </form>
</section>

<section>
    <h2>Tus keys</h2>
    <?php if (empty($keys)): ?>
        <p class="muted">No tenés API keys.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Nombre</th><th>Prefijo</th><th>Scopes</th><th>Último uso</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): $sc = json_decode((string) $k['scopes'], true) ?: []; ?>
                <tr>
                    <td><?= e((string) $k['name']) ?></td>
                    <td><code><?= e((string) $k['key_prefix']) ?>…</code></td>
                    <td class="muted" style="font-size:.8rem"><?= e(implode(', ', $sc)) ?></td>
                    <td class="muted"><?= e((string) ($k['last_used_at'] ?? '—')) ?></td>
                    <td><?= ((int) $k['is_active'] === 1) ? '<span class="badge-status status-accepted">activa</span>' : '<span class="badge-status status-revoked">revocada</span>' ?></td>
                    <td>
                        <?php if ((int) $k['is_active'] === 1): ?>
                            <form method="POST" action="/admin/api-keys/<?= (int) $k['id'] ?>/revoke" style="display:inline" onsubmit="return confirm('¿Revocar esta key?')">
                                <?= CSRF::field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Revocar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
