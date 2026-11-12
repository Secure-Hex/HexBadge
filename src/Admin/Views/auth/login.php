<?php
/**
 * Login: Hermes (normal) y, si ADMIN_LOCAL_LOGIN está activo, el acceso de emergencia
 * con contraseña (solo superadmin).
 *
 * @var string|null $error
 * @var string|null $oldEmail
 * @var bool        $reset
 * @var bool        $localLogin
 * @var bool        $configured
 */
$error    = $error ?? null;
$oldEmail = $oldEmail ?? '';
?>
<div class="auth-card">
    <div class="brand-row">
        <span class="brand-mark"><?= \HexBadge\Core\View::renderPartial('layout/hexbadgelogo') ?></span>
        <b>HexBadge</b>
    </div>
    <h1>Iniciar sesión</h1>
    <p class="auth-subtitle">Panel de administración · SecureHex</p>

    <?php if ($reset): ?>
        <div class="alert alert-success">Tu contraseña fue actualizada. Ya puedes ingresar.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($configured): ?>
        <a class="btn btn-primary btn-block" href="/auth/hermes/login">Entrar con Hermes</a>
    <?php else: ?>
        <div class="alert alert-warn">El inicio de sesión con Hermes no está configurado.</div>
    <?php endif; ?>

    <?php if ($localLogin): ?>
        <p class="auth-subtitle" style="margin-top:1.5rem">Acceso de emergencia (solo superadmin)</p>
        <form method="POST" action="/login">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($oldEmail) ?>" autocomplete="username"
                   required maxlength="255">

            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit" class="btn btn-block">Ingresar con contraseña</button>
        </form>
        <p class="auth-alt"><a href="/forgot-password">¿Olvidaste tu contraseña?</a></p>
    <?php endif; ?>
</div>
