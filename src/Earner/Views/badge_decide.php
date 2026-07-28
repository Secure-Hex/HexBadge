<?php
/**
 * Aceptar o rechazar una credencial recién emitida.
 *
 * @var array<string,mixed> $badge
 * @var array<int,string>   $tags
 * @var string              $verifyUrl
 */
use HexBadge\Core\CSRF;

$b = $badge;
?>
<div class="decide-card">
    <p class="decide-eyebrow"><?= e((string) $b['issuer_name']) ?> te emitió una credencial</p>

    <img class="decide-image" src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="">
    <h1><?= e((string) $b['template_name']) ?></h1>

    <?php if (!empty($b['template_description'])): ?>
        <p class="decide-desc"><?= e((string) $b['template_description']) ?></p>
    <?php endif; ?>

    <?php if (!empty($tags)): ?>
        <p class="decide-tags"><?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></p>
    <?php endif; ?>

    <dl class="decide-meta">
        <dt>Emitida</dt><dd><?= e(date_long((string) $b['issued_at'])) ?></dd>
        <?php if (!empty($b['expires_at'])): ?>
            <dt>Vence</dt><dd><?= e(date_long((string) $b['expires_at'])) ?></dd>
        <?php endif; ?>
    </dl>

    <p class="decide-note">
        Al aceptarla, tu nombre y esta credencial quedan en una página pública que
        cualquiera puede consultar para verificarla.
    </p>

    <div class="decide-actions">
        <form method="POST" action="/me/badge/<?= e((string) $b['uuid']) ?>/accept">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-primary btn-block">Aceptar credencial</button>
        </form>
        <a class="btn btn-block" href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener">Ver cómo se verá</a>
        <form method="POST" action="/me/badge/<?= e((string) $b['uuid']) ?>/reject"
              data-confirm="Vas a rechazar «<?= e((string) $b['template_name']) ?>» de <?= e((string) $b['issuer_name']) ?>. No vas a poder recuperarla desde acá. ¿Confirmás?">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-ghost btn-block decide-reject">Rechazar</button>
        </form>
    </div>
</div>
