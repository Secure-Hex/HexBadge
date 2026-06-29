<?php
/**
 * @var array<string,mixed>            $earner
 * @var array<int,array<string,mixed>> $badges
 * @var array<int,array<string,mixed>> $pending
 * @var bool                           $isOwner
 * @var bool                           $justAccepted
 * @var string                         $verifyBase
 */
$pending = $pending ?? [];
?>
<?php if ($justAccepted): ?>
    <div class="alert alert-success">¡Badge aceptado! Ya forma parte de tu perfil.</div>
<?php endif; ?>

<?php if (!empty($isOwner) && !empty($pending)): ?>
    <div class="pending-banner">
        <div class="pending-head">
            <strong><?= count($pending) ?> badge<?= count($pending) === 1 ? '' : 's' ?> por aceptar</strong>
            <span class="muted">Revisá cada uno y decidí si lo aceptás.</span>
        </div>
        <ul class="pending-list">
            <?php foreach ($pending as $p): ?>
                <li>
                    <img src="<?= e(badge_image_url((string) $p['image_filename'])) ?>" alt="">
                    <div class="pending-info">
                        <strong><?= e((string) $p['template_name']) ?></strong>
                        <span class="muted"><?= e((string) $p['issuer_name']) ?> · <?= e((string) $p['issued_at']) ?></span>
                    </div>
                    <a class="btn btn-sm btn-primary" href="/me/badge/<?= e((string) $p['uuid']) ?>">Ver y decidir</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<h1><?= e((string) $earner['display_name']) ?></h1>
<?php if (!empty($earner['profile_bio'])): ?>
    <p class="muted"><?= nl2br(e((string) $earner['profile_bio'])) ?></p>
<?php endif; ?>
<p class="muted"><?= count($badges) ?> badge<?= count($badges) === 1 ? '' : 's' ?></p>

<?php if (empty($badges)): ?>
    <p class="muted">Todavía no hay badges aceptados en este perfil.</p>
<?php else: ?>
    <div class="badge-grid">
        <?php foreach ($badges as $b): ?>
            <a class="badge-tile" href="<?= e($verifyBase . (string) $b['uuid']) ?>" target="_blank" rel="noopener">
                <img src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="<?= e((string) $b['template_name']) ?>">
                <h3><?= e((string) $b['template_name']) ?></h3>
                <p class="muted" style="font-size:.8rem;margin:0"><?= e((string) $b['issuer_name']) ?></p>
                <?php if (!empty($b['tags'])): ?>
                    <div style="margin-top:.5rem">
                        <?php foreach (array_slice($b['tags'], 0, 3) as $tag): ?><span class="tag"><?= e($tag) ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
