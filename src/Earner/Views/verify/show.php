<?php
/**
 * Página pública de verificación (standalone, sin nav de admin).
 *
 * @var string              $appName
 * @var array<string,mixed> $badge
 * @var array<int,string>   $tags
 * @var bool                $expired
 * @var string              $verifyUrl
 * @var string              $jsonUrl
 * @var string              $imageUrl
 * @var string              $addToProfileUrl
 * @var string              $shareUrl
 */
$b = $badge;
$status = (string) $b['status'];
$earnerName = (string) $b['first_name'] . ' ' . (string) $b['last_name'];

[$stateLabel, $stateClass, $stateIcon] = match (true) {
    $status === 'revoked' => ['Revocado', 'status-revoked', '✗'],
    $expired              => ['Expirado', 'status-pending', '⚠'],
    default               => ['Válido', 'status-accepted', '✓'],
};
$ogDescription = $earnerName . ' obtuvo el badge "' . (string) $b['template_name'] . '" emitido por ' . (string) $b['issuer_name'] . '.';
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
<div class="verify-card">
    <img class="badge-img" src="<?= e(badge_image_url((string) $b['image_filename'])) ?>" alt="<?= e((string) $b['template_name']) ?>">
    <h1 style="margin:1rem 0 .15rem"><?= e((string) $b['template_name']) ?></h1>
    <p style="font-size:1.05rem;margin-top:0;color:var(--text-2)">Otorgado a <strong><?= e($earnerName) ?></strong></p>

    <p><span class="badge-status <?= $stateClass ?>" style="font-size:.9rem;padding:.3rem .85rem"><?= $stateLabel ?></span></p>

    <?php if ($status === 'revoked'): ?>
        <div class="alert alert-error">Este badge fue revocado<?= !empty($b['revoke_reason']) ? ': ' . e((string) $b['revoke_reason']) : '.' ?></div>
    <?php elseif ($expired): ?>
        <div class="alert alert-error">Este badge expiró el <?= e((string) $b['expires_at']) ?>.</div>
    <?php endif; ?>

    <?php if (!empty($b['template_description'])): ?>
        <p class="muted" style="max-width:42ch;margin:.5rem auto 0"><?= e((string) $b['template_description']) ?></p>
    <?php endif; ?>

    <?php if (!empty($tags)): ?>
        <p style="margin-top:.75rem">
            <?php foreach ($tags as $tag): ?><span class="tag"><?= e($tag) ?></span><?php endforeach; ?>
        </p>
    <?php endif; ?>

    <div class="verify-meta">
        <table class="table">
            <tr><th>Emisor</th><td><?= e((string) $b['issuer_name']) ?></td></tr>
            <tr><th>Fecha de emisión</th><td><?= e((string) $b['issued_at']) ?></td></tr>
            <?php if (!empty($b['expires_at'])): ?><tr><th>Expira</th><td><?= e((string) $b['expires_at']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <?php if ($status !== 'revoked'): ?>
    <div style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap">
        <a class="btn btn-primary" href="<?= e($addToProfileUrl) ?>" target="_blank" rel="noopener">Agregar a LinkedIn</a>
        <?php if (!empty($certificateUrl)): ?>
            <a class="btn" href="<?= e($certificateUrl) ?>" target="_blank" rel="noopener">Descargar diploma (PDF)</a>
        <?php endif; ?>
        <a class="btn" href="<?= e($shareUrl) ?>" target="_blank" rel="noopener">Compartir</a>
        <a class="btn" href="<?= e($jsonUrl) ?>" target="_blank" rel="noopener">Ver JSON</a>
    </div>
    <?php else: ?>
    <div><a class="btn" href="<?= e($jsonUrl) ?>" target="_blank" rel="noopener">Ver assertion JSON</a></div>
    <?php endif; ?>

    <p class="muted" style="font-size:.78rem;margin-top:1.5rem">Verificado con <strong>HexBadge</strong>, una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a></p>
</div>
</body>
</html>
