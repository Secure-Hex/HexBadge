<?php
/**
 * Botón "Acerca de" + modal con la marca SecureHex, compartido por los footers
 * de ambos portales (admin y earner).
 *
 * El modal usa el truco CSS :target (sin JavaScript) para respetar la CSP
 * (script-src 'self', sin inline). El logo reutiliza el partial layout/securelogo
 * que existe en las dos apps, resuelto por el basePath de View.
 */
$aboutVersion = (string) config('app.version', '1.0');
?>
<a href="#about-hexbadge" class="about-btn" aria-label="Acerca de HexBadge">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
        <path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <circle cx="12" cy="7.6" r="1.2" fill="currentColor"/>
    </svg>
    <span>Acerca de</span>
</a>

<div id="about-hexbadge" class="about-modal" role="dialog" aria-modal="true" aria-label="Acerca de HexBadge">
    <a href="#" class="about-backdrop" aria-label="Cerrar" tabindex="-1"></a>
    <div class="about-card">
        <a href="#" class="about-close" aria-label="Cerrar">&times;</a>
        <span class="about-logo"><?= \HexBadge\Core\View::renderPartial('layout/securelogo') ?></span>
        <p class="about-lead">Herramienta desarrollada por <strong>SecureHex</strong>.</p>
        <p class="about-ver">Versión <?= e($aboutVersion) ?></p>
        <a class="about-web" href="https://securehex.cl" target="_blank" rel="noopener">securehex.cl</a>
    </div>
</div>
