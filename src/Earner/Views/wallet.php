<?php
/**
 * Perfil de una persona: su identidad y su vitrina de credenciales.
 * La misma vista sirve al público y a la dueña ($isOwner).
 *
 * @var array<string,mixed>            $earner
 * @var array<int,array<string,mixed>> $badges
 * @var array<int,array<string,mixed>> $pending
 * @var bool                           $isOwner
 * @var bool                           $justAccepted
 * @var string                         $verifyBase
 */
$pending = $pending ?? [];
$name    = (string) $earner['display_name'];
$initial = strtoupper(mb_substr($name, 0, 1));
$total   = count($badges);

// Solo las redes que el receptor cargó (definiciones centralizadas).
$networks = [];
foreach (social_networks() as $net) {
    $url = (string) ($earner[$net['key']] ?? '');
    if ($url !== '') {
        $networks[] = $net + ['url' => $url];
    }
}
$profileUrl = rtrim((string) config('app.earner_url'), '/') . '/earner/' . (string) $earner['uuid'];
?>
<?php if ($justAccepted): ?>
    <div class="alert alert-success" role="status">¡Badge aceptado! Ya forma parte de tu perfil.</div>
<?php endif; ?>

<?php if (!empty($isOwner) && !empty($pending)): ?>
    <div class="pending-banner">
        <div class="pending-head">
            <strong><?= count($pending) ?> credencial<?= count($pending) === 1 ? '' : 'es' ?> esperando tu respuesta</strong>
            <span class="muted">Revisá cada una y decidí si la aceptás. Hasta entonces no aparece en tu perfil.</span>
        </div>
        <ul class="pending-list">
            <?php foreach ($pending as $p): ?>
                <li>
                    <img src="<?= e(badge_image_url((string) $p['image_filename'])) ?>" alt="" loading="lazy">
                    <div class="pending-info">
                        <strong><?= e((string) $p['template_name']) ?></strong>
                        <span class="muted"><?= e((string) $p['issuer_name']) ?> · <?= e(date_long((string) $p['issued_at'])) ?></span>
                    </div>
                    <a class="btn btn-sm btn-primary" href="/me/badge/<?= e((string) $p['uuid']) ?>">Ver y decidir</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<header class="profile-header">
    <div class="profile-cover"<?php if (!empty($earner['cover_filename'])): ?> style="background-image:url('<?= e(profile_image_url((string) $earner['cover_filename'])) ?>')"<?php endif; ?>></div>
    <div class="profile-id">
        <div class="profile-avatar">
            <?php if (!empty($earner['avatar_filename'])): ?>
                <img src="<?= e(profile_image_url((string) $earner['avatar_filename'])) ?>" alt="">
            <?php else: ?>
                <span aria-hidden="true"><?= e($initial) ?></span>
            <?php endif; ?>
        </div>

        <div class="profile-main">
            <div class="profile-text">
                <h1><?= e($name) ?></h1>
                <?php if (!empty($earner['profile_bio'])): ?>
                    <p class="profile-bio"><?= nl2br(e((string) $earner['profile_bio'])) ?></p>
                <?php endif; ?>
                <?php if ($networks !== []): ?>
                    <div class="social-links">
                        <?php foreach ($networks as $n): ?>
                            <a class="social-link" style="--brand:<?= e($n['brand']) ?>" href="<?= e($n['url']) ?>" target="_blank" rel="noopener nofollow">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $n['icon'] ?>"/></svg>
                                <span><?= e($n['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="profile-side">
                <p class="profile-stat">
                    <strong><?= $total ?></strong>
                    <span><?= $total === 1 ? 'credencial verificada' : 'credenciales verificadas' ?></span>
                </p>
                <div class="profile-actions">
                    <?php if (!empty($isOwner)): ?>
                        <a class="btn btn-sm" href="/me/profile">Editar perfil</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm" data-copy="<?= e($profileUrl) ?>">
                        <span>Copiar enlace</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</header>

<section class="credentials">
    <h2>Credenciales <?php if ($total > 0): ?><span><?= $total ?></span><?php endif; ?></h2>

    <?php if ($total === 0): ?>
        <div class="empty-state">
            <?php if (!empty($isOwner)): ?>
                <p><strong>Todavía no tenés credenciales aceptadas.</strong></p>
                <p class="muted">Cuando una organización te emita una, te llega un correo con el enlace para aceptarla. Al aceptarla aparece acá y cualquiera puede verificarla.</p>
            <?php else: ?>
                <p><strong>Esta persona todavía no tiene credenciales publicadas.</strong></p>
                <p class="muted">Cuando acepte una, va a aparecer en esta página.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <ul class="badge-grid">
            <?php foreach ($badges as $b): ?>
                <?php $tags = is_array($b['tags'] ?? null) ? $b['tags'] : []; ?>
                <li>
                    <a class="badge-tile" href="<?= e($verifyBase . (string) $b['uuid']) ?>">
                        <span class="badge-tile-media">
                            <img src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="" loading="lazy">
                        </span>
                        <span class="badge-tile-body">
                            <strong class="badge-tile-name"><?= e((string) $b['template_name']) ?></strong>
                            <span class="badge-tile-issuer"><?= e((string) $b['issuer_name']) ?></span>
                            <span class="badge-tile-date"><?= e(date_long((string) $b['issued_at'])) ?></span>
                        </span>
                        <?php if ($tags !== []): ?>
                            <span class="badge-tile-tags">
                                <?php foreach (array_slice($tags, 0, 2) as $tag): ?>
                                    <span class="tag"><?= e($tag) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($tags) > 2): ?>
                                    <span class="tag tag-more">+<?= count($tags) - 2 ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <span class="badge-tile-verify">Ver credencial verificada →</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
