<?php
/**
 * Layout del portal earner (público).
 *
 * @var string      $content
 * @var string      $appName
 * @var string|null $pageTitle
 */
use HexBadge\Core\View;
use HexBadge\Core\Session;
use HexBadge\Earner\EarnerAuth;

$pageTitle    = $pageTitle ?? null;
$flashSuccess = Session::flash('success');
$flashError   = Session::flash('error');
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ? $pageTitle . ' — ' . $appName : $appName) ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Saltar al contenido</a>
<header class="public-header">
    <div class="inner">
        <a class="brand" href="/">
            <span class="brand-mark" style="color:var(--primary)"><?= View::renderPartial('layout/securelogo') ?></span>
            <?= e($appName) ?>
        </a>
        <div class="people-search" data-people-search>
            <svg class="people-search-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
            <input type="search" class="people-search-input" placeholder="Buscar personas…" autocomplete="off" aria-label="Buscar personas" data-people-input>
            <div class="people-search-results" data-people-results hidden></div>
        </div>
        <nav aria-label="Principal">
            <?php if (EarnerAuth::check()): ?>
                <a href="/earner/<?= e((string) Session::get('earner_uuid')) ?>">Mis badges</a>
                <a href="/me/profile">Perfil</a>
                <a href="/me/security">Seguridad</a>
                <a class="btn btn-sm" href="/logout">Salir</a>
            <?php else: ?>
                <a class="btn btn-sm btn-primary" href="/login">Ingresar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container" id="main">
    <?php if ($flashSuccess): ?><div class="alert alert-success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer">
    <p><strong><?= e($appName) ?></strong> — una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a></p>
    <p style="opacity:.8">&copy; <?= date('Y') ?> SecureHex · securehex.cl</p>
    <?php require BASE_PATH . '/src/Shared/about.php'; ?>
</footer>
<script src="<?= asset('js/confirm.js') ?>" defer></script>
<script src="<?= asset('js/copy.js') ?>" defer></script>
<script src="<?= asset('js/search.js') ?>" defer></script>
</body>
</html>
