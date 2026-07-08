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
<header class="public-header">
    <div class="inner">
        <a class="brand" href="/">
            <span class="brand-mark" style="color:var(--primary)"><?= View::renderPartial('layout/securelogo') ?></span>
            <?= e($appName) ?>
        </a>
        <nav>
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
<main class="container">
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-error"><?= e($flashError) ?></div><?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer">
    <p><strong><?= e($appName) ?></strong> — una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a></p>
    <p style="opacity:.8">&copy; <?= date('Y') ?> SecureHex · securehex.cl</p>
</footer>
<script src="<?= asset('js/search.js') ?>" defer></script>
</body>
</html>
