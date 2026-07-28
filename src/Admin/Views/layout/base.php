<?php
/**
 * Layout principal del panel admin (shell con sidebar + topbar).
 *
 * @var string                       $content
 * @var string                       $appName
 * @var array<string,mixed>|null     $currentUser
 * @var string|null                  $pageTitle
 */
use HexBadge\Core\View;
use HexBadge\Core\Session;

$pageTitle    = $pageTitle ?? null;
$name         = (string) ($currentUser['name'] ?? '');
$initial      = strtoupper(mb_substr(trim($name) !== '' ? $name : 'U', 0, 1));
$flashSuccess = Session::flash('success');
$flashError   = Session::flash('error');
// Para lo que salió a medias: la acción se completó pero hay algo que el
// usuario tiene que saber. Sin este caso, un flash de aviso se descartaba en
// silencio y quedaba peor que no haberlo escrito.
$flashWarning = Session::flash('warning');
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
<?php if (!empty($currentUser)): ?>
<div class="app-shell">
    <?= View::renderPartial('layout/nav', ['currentUser' => $currentUser, 'appName' => $appName]) ?>
    <div class="app-main">
        <header class="topbar">
            <div class="spacer"></div>
            <div class="topbar-user">
                <span class="avatar"><?= e($initial) ?></span>
                <a href="/admin/account"><?= e($name) ?></a>
                <a class="btn btn-sm" href="/logout">Salir</a>
            </div>
        </header>
        <main class="content" id="main">
            <?php if ($flashSuccess): ?><div class="alert alert-success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashWarning): ?><div class="alert alert-warn" role="alert"><?= e($flashWarning) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="alert alert-error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<?php else: ?>
<div class="auth-wrap">
    <main id="main" style="width:100%;max-width:420px">
        <?php if ($flashSuccess): ?><div class="alert alert-success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashWarning): ?><div class="alert alert-warn" role="alert"><?= e($flashWarning) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
        <?= $content ?>
        <p style="text-align:center;margin-top:1.25rem;font-size:.82rem;color:var(--muted)">
            <strong>HexBadge</strong> — una herramienta de
            <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a>
        </p>
    </main>
</div>
<?php endif; ?>
<script src="<?= asset('js/confirm.js') ?>" defer></script>
<script src="<?= asset('js/copy.js') ?>" defer></script>
<script src="<?= asset('js/filters.js') ?>" defer></script>
<script src="<?= asset('js/company-multiselect.js') ?>" defer></script>
</body>
</html>
