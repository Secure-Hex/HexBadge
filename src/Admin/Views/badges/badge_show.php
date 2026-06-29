<?php
/**
 * @var array<string,mixed> $badge
 * @var string              $verifyUrl
 */
use HexBadge\Core\CSRF;

$b = $badge;
?>
<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap">
    <img src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt=""
         style="width:140px;height:140px;object-fit:contain;background:var(--surface);border-radius:12px;padding:8px">
    <div style="flex:1;min-width:280px">
        <h1 style="margin-top:0"><?= e((string) $b['template_name']) ?></h1>
        <p><span class="badge-status status-<?= e((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span></p>
        <table class="table">
            <tr><th>Receptor</th><td><?= e((string) $b['first_name'] . ' ' . (string) $b['last_name']) ?></td></tr>
            <tr><th>Email</th><td><?= e((string) $b['earner_email']) ?></td></tr>
            <tr><th>Emitido</th><td><?= e((string) $b['issued_at']) ?> (<?= e((string) $b['issued_via']) ?>)</td></tr>
            <?php if (!empty($b['expires_at'])): ?><tr><th>Expira</th><td><?= e((string) $b['expires_at']) ?></td></tr><?php endif; ?>
            <?php if (!empty($b['accepted_at'])): ?><tr><th>Aceptado</th><td><?= e((string) $b['accepted_at']) ?></td></tr><?php endif; ?>
            <?php if ($b['status'] === 'revoked'): ?><tr><th>Motivo revocación</th><td><?= e((string) $b['revoke_reason']) ?></td></tr><?php endif; ?>
            <tr><th>Verificación</th><td><a href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener"><?= e($verifyUrl) ?></a></td></tr>
        </table>

        <?php if ($b['status'] === 'pending'): ?>
            <form method="POST" action="/admin/badges/<?= e((string) $b['uuid']) ?>/resend" style="margin-top:1rem">
                <?= CSRF::field() ?>
                <button type="submit" class="btn">Reenviar correo de aceptación</button>
                <span class="muted" style="font-size:.82rem;margin-left:.5rem">Genera un enlace nuevo (invalida el anterior).</span>
            </form>
        <?php endif; ?>

        <?php if ($b['status'] !== 'revoked'): ?>
            <form method="POST" action="/admin/badges/<?= e((string) $b['uuid']) ?>/revoke" style="margin-top:1rem;max-width:480px"
                  onsubmit="return confirm('¿Revocar este badge? Es irreversible.')">
                <?= CSRF::field() ?>
                <label for="reason">Motivo de revocación</label>
                <input type="text" id="reason" name="reason" maxlength="500" placeholder="Ej: emitido por error">
                <button type="submit" class="btn btn-danger" style="margin-top:.6rem">Revocar badge</button>
            </form>
        <?php endif; ?>
    </div>
</div>
