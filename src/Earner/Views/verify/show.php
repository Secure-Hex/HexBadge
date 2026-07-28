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

// Veredicto: la respuesta que vino a buscar quien abre esta página. Va primero
// y en grande; todo lo demás es contexto.
$issuer   = (string) $b['issuer_name'];
$issuedOn = date_long((string) $b['issued_at']);
[$verdictClass, $verdictTitle, $verdictDetail, $verdictIcon] = match (true) {
    $status === 'revoked' => [
        'verdict-revoked',
        'Credencial revocada',
        $issuer . ' revocó esta credencial'
            . (!empty($b['revoke_reason']) ? ': ' . (string) $b['revoke_reason'] : '.')
            . ' Ya no acredita el logro.',
        'M12 2a10 10 0 100 20 10 10 0 000-20zm4.9 13.5L15.5 16.9 12 13.4l-3.5 3.5-1.4-1.4 3.5-3.5-3.5-3.5 1.4-1.4 3.5 3.5 3.5-3.5 1.4 1.4-3.5 3.5 3.5 3.5z',
    ],
    $expired => [
        'verdict-expired',
        'Credencial expirada',
        'Emitida por ' . $issuer . ' el ' . $issuedOn . '. Venció el ' . date_long((string) $b['expires_at']) . '.',
        'M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v5.6l4 2.4-.8 1.3-4.7-2.8V7z',
    ],
    default => [
        'verdict-valid',
        'Credencial válida',
        'Emitida por ' . $issuer . ' el ' . $issuedOn . ', verificada contra el registro de HexBadge.',
        'M12 2a10 10 0 100 20 10 10 0 000-20zm-1.2 14.3l-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z',
    ],
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

// Botones de compartir: hoy solo para la persona dueña de la credencial
// (ver $isOwner más abajo). Abrirlos a cualquier visitante ayudaría a la
// difusión, pero es una decisión de privacidad sobre datos de un tercero.
$encAll  = rawurlencode($ogDescription . ' ' . $verifyUrl);
$encUrl  = rawurlencode($verifyUrl);
$encText = rawurlencode($ogDescription);
$copyIcon = 'M13.06 8.11l1.415 1.415a7 7 0 010 9.9l-.354.353a7 7 0 01-9.9-9.9l1.415 1.415a5 5 0 007.071 7.07l.354-.353a5 5 0 000-7.071l-1.415-1.415 1.415-1.414zm6.718 6.011l-1.414-1.415a5 5 0 00-7.071-7.07l-.354.353a5 5 0 000 7.071l1.415 1.415-1.415 1.414-1.414-1.414a7 7 0 010-9.9l.354-.353a7 7 0 019.9 9.9l-1.415 1.414z';
$shareButtons = [
    ['LinkedIn', $shareUrl, '#0A66C2', $liIcon],
    ['WhatsApp', 'https://wa.me/?text=' . $encAll, '#25D366', 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488'],
    ['X', 'https://twitter.com/intent/tweet?text=' . $encText . '&url=' . $encUrl, '#000000', 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
    ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $encUrl, '#1877F2', 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073'],
    ['Email', 'mailto:?subject=' . rawurlencode('Credencial verificada: ' . (string) $b['template_name']) . '&body=' . $encAll, '#697587', 'M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0zM22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0z'],
];
// Snippet para insertar en cualquier web: imagen del badge + título de la
// acreditación y persona a la que se otorgó, todo enlazado a la verificación.
$embName    = htmlspecialchars((string) $b['template_name'], ENT_QUOTES);
$embGrantee = htmlspecialchars($earnerName, ENT_QUOTES);
$embedCode = '<a href="' . $verifyUrl . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:12px;max-width:360px;padding:10px 14px;border:1px solid #e5e7eb;border-radius:10px;font-family:Arial,Helvetica,sans-serif;text-decoration:none;color:#1a2233">'
    . '<img src="' . $imageUrl . '" alt="' . $embName . '" width="72" height="72" style="border:0;flex:none">'
    . '<span style="display:flex;flex-direction:column;gap:3px">'
    . '<strong style="font-size:15px;line-height:1.2">' . $embName . '</strong>'
    . '<span style="font-size:13px;color:#697587">' . $embGrantee . '</span>'
    . '</span></a>';
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

    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <style>
    .share-block{margin-top:1.1rem}
    .share-block h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:0 0 .55rem}
    .share-grid{display:flex;flex-wrap:wrap;gap:.5rem}
    .share-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .8rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--brand,#1a2233);font:inherit;font-size:.85rem;line-height:1;cursor:pointer;text-decoration:none;transition:background .15s,color .15s,border-color .15s}
    .share-btn:hover{background:var(--brand,#1a2233);color:#fff;border-color:var(--brand,#1a2233)}
    .share-btn svg{width:18px;height:18px;fill:currentColor;flex:none}
    .share-embed{margin-top:.7rem}
    .share-embed summary{cursor:pointer;font-size:.85rem;color:var(--muted)}
    .share-embed textarea{width:100%;min-height:70px;margin:.5rem 0;font-family:ui-monospace,monospace;font-size:.72rem;padding:.5rem;border:1px solid var(--border);border-radius:8px;resize:vertical}
    </style>
</head>
<body class="verify-page"<?= ($status !== 'revoked' && !$expired) ? ' data-celebrate="1"' : '' ?>>
<main class="verify-shell" id="main">

    <!-- Veredicto: primero, antes que cualquier otra cosa -->
    <div class="verdict <?= $verdictClass ?>" role="status">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $verdictIcon ?>"/></svg>
        <div>
            <strong><?= $verdictTitle ?></strong>
            <p><?= e($verdictDetail) ?></p>
        </div>
    </div>

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
            <h1><?= e((string) $b['template_name']) ?></h1>
            <p class="bv-grantee">Otorgado a <strong><?= e($earnerName) ?></strong></p>

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
                <dt>Emisor</dt><dd><?= e($issuer) ?></dd>
                <dt>Fecha de emisión</dt><dd><?= e($issuedOn) ?></dd>
                <?php if (!empty($b['expires_at'])): ?><dt>Expira</dt><dd><?= e(date_long((string) $b['expires_at'])) ?></dd><?php endif; ?>
                <dt>ID de verificación</dt><dd><code class="bv-id"><?= e((string) $b['uuid']) ?></code></dd>
            </dl>

            <div class="bv-actions">
                <?php if ($isOwner && $status !== 'revoked'): ?>
                    <div class="bv-row">
                        <a class="btn btn-linkedin" href="<?= e($addToProfileUrl) ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $liIcon ?>"/></svg>Agregar a LinkedIn
                        </a>
                    </div>
                <?php endif; ?>
                <div class="bv-row">
                    <?php if (!empty($certificateUrl)): ?>
                        <a class="btn" href="<?= e($certificateUrl) ?>" target="_blank" rel="noopener">Ver diploma (PDF)</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="<?= e($jsonUrl) ?>" target="_blank" rel="noopener">Ver datos Open Badge</a>
                </div>

                <?php if ($isOwner && $status !== 'revoked'): ?>
                <div class="share-block">
                    <h3>Compartir</h3>
                    <div class="share-grid">
                        <?php foreach ($shareButtons as [$label, $shUrl, $brand, $icon]): ?>
                            <a class="share-btn" style="--brand:<?= e($brand) ?>" href="<?= e($shUrl) ?>" target="_blank" rel="noopener nofollow">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icon ?>"/></svg><span><?= e($label) ?></span>
                            </a>
                        <?php endforeach; ?>
                        <button type="button" class="share-btn" style="--brand:#697587" data-copy="<?= e($verifyUrl) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $copyIcon ?>"/></svg><span>Copiar enlace</span>
                        </button>
                    </div>
                    <details class="share-embed">
                        <summary>Insertar en una web (HTML)</summary>
                        <textarea id="embed-code" readonly data-select><?= e($embedCode) ?></textarea>
                        <button type="button" class="share-btn" style="--brand:#1565d8" data-copy-el="#embed-code"><span>Copiar código</span></button>
                    </details>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <!-- Quién la obtuvo: contexto, después de la credencial -->
    <aside class="owner-card">
        <div class="owner-avatar">
            <?php if (!empty($b['avatar_filename'])): ?>
                <img src="<?= e(profile_image_url((string) $b['avatar_filename'])) ?>" alt="">
            <?php else: ?>
                <span aria-hidden="true"><?= e($initial) ?></span>
            <?php endif; ?>
        </div>
        <div class="owner-id">
            <h2><?= e($earnerName) ?></h2>
            <?php if (!empty($b['profile_bio'])): ?>
                <p class="owner-bio"><?= e(mb_strimwidth(trim((string) $b['profile_bio']), 0, 160, '…')) ?></p>
            <?php endif; ?>
            <p class="owner-links">
                <a href="/earner/<?= e((string) $b['earner_uuid']) ?>">Ver todos sus badges →</a>
                <?php foreach ($networks as $n): ?>
                    <a class="owner-social" href="<?= e($n['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= e($n['label']) ?>" style="--brand:<?= e($n['brand']) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $n['icon'] ?>"/></svg>
                    </a>
                <?php endforeach; ?>
            </p>
        </div>
    </aside>

    <p class="verify-foot">Verificado con <strong>HexBadge</strong>, una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a></p>
</main>
<script src="<?= asset('js/copy.js') ?>" defer></script>
<script src="<?= asset('js/verify.js') ?>" defer></script>
</body>
</html>
