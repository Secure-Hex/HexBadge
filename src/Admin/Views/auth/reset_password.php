<?php
/**
 * @var string            $token
 * @var bool              $valid
 * @var array<int,string> $errors
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\View;
?>
<div class="auth-card">
    <div class="brand-row">
        <span class="brand-mark"><?= View::renderPartial('layout/securelogo') ?></span>
        <b>HexBadge</b>
    </div>
    <h1>Nueva contraseña</h1>

    <?php if (!$valid): ?>
        <div class="alert alert-error">El enlace no es válido o expiró. Pedí uno nuevo.</div>
        <a class="btn btn-primary btn-block" href="/forgot-password">Pedir otro enlace</a>
    <?php else: ?>
        <p class="auth-subtitle">Elegí una contraseña nueva para tu cuenta.</p>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="/reset-password/<?= e($token) ?>" autocomplete="off">
            <?= CSRF::field() ?>
            <label for="password">Nueva contraseña <span class="muted">· mín. 12 caracteres</span></label>
            <input type="password" id="password" name="password" minlength="12" required autofocus>
            <label for="password_confirm">Repetir contraseña</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="12" required>
            <button type="submit" class="btn btn-primary btn-block">Guardar contraseña</button>
        </form>
    <?php endif; ?>
</div>
