<?php
/**
 * @var array<string,mixed> $badge
 * @var array<int,string>   $tags
 * @var string              $verifyUrl
 */
use HexBadge\Core\CSRF;
$b = $badge;
?>
<div class="auth-card" style="max-width:520px;text-align:center">
    <img src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="<?= e((string) $b['template_name']) ?>"
         style="width:150px;height:150px;object-fit:contain;background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:12px">
    <h1 style="margin:1rem 0 .15rem"><?= e((string) $b['template_name']) ?></h1>
    <p class="muted" style="margin-top:0"><?= e((string) $b['issuer_name']) ?> te emitió este badge</p>

    <?php if (!empty($b['template_description'])): ?>
        <p style="color:var(--text-2);max-width:42ch;margin:.75rem auto"><?= e((string) $b['template_description']) ?></p>
    <?php endif; ?>

    <?php if (!empty($tags)): ?>
        <p><?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></p>
    <?php endif; ?>

    <div class="verify-meta" style="text-align:left;margin:1.25rem 0">
        <table class="table" style="box-shadow:none;margin:0">
            <tr><th>Emitido</th><td><?= e((string) $b['issued_at']) ?></td></tr>
            <?php if (!empty($b['expires_at'])): ?><tr><th>Expira</th><td><?= e((string) $b['expires_at']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <div style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap">
        <form method="POST" action="/me/badge/<?= e((string) $b['uuid']) ?>/accept">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-primary">Aceptar badge</button>
        </form>
        <form method="POST" action="/me/badge/<?= e((string) $b['uuid']) ?>/reject" onsubmit="return confirm('¿Rechazar este badge? No podrás recuperarlo desde acá.')">
            <?= CSRF::field() ?>
            <button type="submit" class="btn">Rechazar</button>
        </form>
    </div>

    <p style="margin-top:1rem"><a class="muted" href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener" style="font-size:.85rem">Ver la verificación pública</a></p>
</div>
