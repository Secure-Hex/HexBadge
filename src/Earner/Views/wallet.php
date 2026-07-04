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
<div class="people-search" data-people-search>
    <svg class="people-search-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
    <input type="search" class="people-search-input" placeholder="Buscar personas…" autocomplete="off" aria-label="Buscar personas" data-people-input>
    <div class="people-search-results" data-people-results hidden></div>
</div>

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

<?php
// Solo las redes que el receptor cargó (definiciones centralizadas).
$networks = [];
foreach (social_networks() as $net) {
    $url = (string) ($earner[$net['key']] ?? '');
    if ($url !== '') {
        $networks[] = $net + ['url' => $url];
    }
}
$initial = strtoupper(mb_substr((string) $earner['display_name'], 0, 1));
?>
<header class="profile-header">
    <div class="profile-cover"<?php if (!empty($earner['cover_filename'])): ?> style="background-image:url('<?= e(profile_image_url((string) $earner['cover_filename'])) ?>')"<?php endif; ?>></div>
    <div class="profile-id">
        <div class="profile-avatar">
            <?php if (!empty($earner['avatar_filename'])): ?>
                <img src="<?= e(profile_image_url((string) $earner['avatar_filename'])) ?>" alt="<?= e((string) $earner['display_name']) ?>">
            <?php else: ?>
                <span><?= e($initial) ?></span>
            <?php endif; ?>
        </div>
        <h1><?= e((string) $earner['display_name']) ?></h1>
        <?php if (!empty($earner['profile_bio'])): ?>
            <p class="profile-bio"><?= nl2br(e((string) $earner['profile_bio'])) ?></p>
        <?php endif; ?>
        <?php if ($networks !== []): ?>
            <div class="social-links">
                <?php foreach ($networks as $n): ?>
                    <a class="social-link" style="--brand:<?= e($n['brand']) ?>" href="<?= e($n['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= e($n['label']) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $n['icon'] ?>"/></svg>
                        <span><?= e($n['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="muted profile-count"><?= count($badges) ?> badge<?= count($badges) === 1 ? '' : 's' ?></p>
    </div>
</header>

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
