<?php
/**
 * Página pública de verificación (standalone, sin nav de admin).
 *
 * @var string              $appName
 * @var array<string,mixed> $badge
 * @var bool                $isOwner
 * @var array<int,string>   $tags
 * @var bool                $expired
 * @var string              $verifyUrl
 * @var string              $jsonUrl
 * @var string              $imageUrl
 * @var string              $addToProfileUrl
 * @var string              $shareUrl
 * @var string|null         $certificateUrl
 */
$b = $badge;
$status     = (string) $b['status'];
$earnerName = (string) ($b['display_name'] ?? trim((string) $b['first_name'] . ' ' . (string) $b['last_name']));
$initial    = strtoupper(mb_substr($earnerName !== '' ? $earnerName : '?', 0, 1));

[$stateLabel, $stateClass] = match (true) {
    $status === 'revoked' => ['Revocado', 'status-revoked'],
    $expired              => ['Expirado', 'status-pending'],
    default               => ['Válido', 'status-accepted'],
};
$ogDescription = $earnerName . ' obtuvo el badge "' . (string) $b['template_name'] . '" emitido por ' . (string) $b['issuer_name'] . '.';

// Redes del receptor (solo las cargadas) + logo de LinkedIn para los botones.
$networks = [];
$liIcon   = '';
foreach (social_networks() as $net) {
    if ($net['key'] === 'linkedin_url') {
        $liIcon = $net['icon'];
    }
    $url = (string) ($b[$net['key']] ?? '');
    if ($url !== '') {
        $networks[] = $net + ['url' => $url];
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación — <?= e((string) $b['template_name']) ?></title>

    <!-- Open Graph: vista previa rica al compartir (LinkedIn, WhatsApp, etc.) -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e((string) $b['template_name'] . ' — ' . $earnerName) ?>">
    <meta property="og:description" content="<?= e($ogDescription) ?>">
    <meta property="og:image" content="<?= e($imageUrl) ?>">
    <meta property="og:url" content="<?= e($verifyUrl) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e((string) $b['template_name']) ?>">
    <meta name="twitter:description" content="<?= e($ogDescription) ?>">
    <meta name="twitter:image" content="<?= e($imageUrl) ?>">

    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="verify-page">
<div class="verify-shell">

    <!-- Perfil del receptor -->
    <header class="profile-header">
        <div class="profile-cover"<?php if (!empty($b['cover_filename'])): ?> style="background-image:url('<?= e(profile_image_url((string) $b['cover_filename'])) ?>')"<?php endif; ?>></div>
        <div class="profile-id">
            <div class="profile-avatar">
                <?php if (!empty($b['avatar_filename'])): ?>
                    <img src="<?= e(profile_image_url((string) $b['avatar_filename'])) ?>" alt="<?= e($earnerName) ?>">
                <?php else: ?>
                    <span><?= e($initial) ?></span>
                <?php endif; ?>
            </div>
            <h1><?= e($earnerName) ?></h1>
            <?php if (!empty($b['profile_bio'])): ?>
                <p class="profile-bio"><?= nl2br(e((string) $b['profile_bio'])) ?></p>
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
            <p class="profile-count"><a href="/earner/<?= e((string) $b['earner_uuid']) ?>">Ver todos sus badges →</a></p>
        </div>
    </header>

    <!-- Badge verificado -->
    <article class="badge-verify card">
        <div class="badge-verify-media">
            <?php if (!empty($logoUrl)): ?>
                <div class="bv-logo"><img src="<?= e($logoUrl) ?>" alt="<?= e((string) ($b['company_name'] ?? $b['issuer_name'] ?? '')) ?>"></div>
            <?php endif; ?>
            <img src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="<?= e((string) $b['template_name']) ?>">
            <span class="badge-status <?= $stateClass ?>"><?= $stateLabel ?></span>
        </div>

        <div class="badge-verify-body">
            <p class="bv-eyebrow">Credencial verificada</p>
            <h2><?= e((string) $b['template_name']) ?></h2>
            <p class="bv-grantee">Otorgado a <strong><?= e($earnerName) ?></strong></p>

            <?php if ($status === 'revoked'): ?>
                <div class="alert alert-error">Este badge fue revocado<?= !empty($b['revoke_reason']) ? ': ' . e((string) $b['revoke_reason']) : '.' ?></div>
            <?php elseif ($expired): ?>
                <div class="alert alert-error">Este badge expiró el <?= e((string) $b['expires_at']) ?>.</div>
            <?php endif; ?>

            <?php if (!empty($b['template_description'])): ?>
                <p class="bv-desc"><?= nl2br(e((string) $b['template_description'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($b['criteria_text']) || !empty($b['criteria_url'])): ?>
                <div class="bv-block">
                    <h3>Criterios de obtención</h3>
                    <?php if (!empty($b['criteria_text'])): ?>
                        <p><?= nl2br(e((string) $b['criteria_text'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($b['criteria_url'])): ?>
                        <p><a href="<?= e((string) $b['criteria_url']) ?>" target="_blank" rel="noopener">Ver criterios completos →</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($tags)): ?>
                <div class="bv-block">
                    <h3>Competencias</h3>
                    <div class="bv-tags"><?php foreach ($tags as $tag): ?><span class="tag"><?= e($tag) ?></span><?php endforeach; ?></div>
                </div>
            <?php endif; ?>

            <dl class="meta-list">
                <dt>Emisor</dt><dd><?= e((string) $b['issuer_name']) ?></dd>
                <dt>Fecha de emisión</dt><dd><?= e((string) $b['issued_at']) ?></dd>
                <?php if (!empty($b['expires_at'])): ?><dt>Expira</dt><dd><?= e((string) $b['expires_at']) ?></dd><?php endif; ?>
                <dt>ID de verificación</dt><dd><code class="bv-id"><?= e((string) $b['uuid']) ?></code></dd>
            </dl>

            <div class="bv-actions">
                <?php if ($isOwner && $status !== 'revoked'): ?>
                    <div class="bv-row">
                        <a class="btn btn-linkedin" href="<?= e($addToProfileUrl) ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $liIcon ?>"/></svg>Agregar a LinkedIn
                        </a>
                        <a class="btn btn-linkedin-outline" href="<?= e($shareUrl) ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $liIcon ?>"/></svg>Compartir en LinkedIn
                        </a>
                    </div>
                <?php endif; ?>
                <div class="bv-row">
                    <?php if (!empty($certificateUrl)): ?>
                        <a class="btn" href="<?= e($certificateUrl) ?>" target="_blank" rel="noopener">Ver diploma (PDF)</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="<?= e($jsonUrl) ?>" target="_blank" rel="noopener">Ver datos Open Badge</a>
                </div>
            </div>
        </div>
    </article>

    <p class="verify-foot">Verificado con <strong>HexBadge</strong>, una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a></p>
</div>
</body>
</html>
