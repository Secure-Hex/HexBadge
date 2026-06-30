<?php
/**
 * @var array<string,mixed> $earner
 * @var bool                $totpEnabled
 * @var array<int,string>   $errors
 */
use HexBadge\Core\CSRF;
?>
<div class="pf-wrap">
    <div class="pf-head">
        <h1>Seguridad</h1>
        <p class="muted">Gestioná tu contraseña y la verificación en dos pasos.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card sec-card">
        <div class="sec-card-head">
            <h2>Contraseña</h2>
        </div>
        <p class="muted sec-card-desc">Cambiala cuando quieras. Te pedimos la actual para confirmar.</p>
        <form method="POST" action="/me/security/password" autocomplete="off">
            <?= CSRF::field() ?>
            <label for="current_password">Contraseña actual</label>
            <input type="password" id="current_password" name="current_password" required>
            <label for="new_password">Nueva contraseña <span class="muted">· mín. 12 caracteres</span></label>
            <input type="password" id="new_password" name="new_password" minlength="12" required>
            <label for="new_password_confirm">Repetir nueva contraseña</label>
            <input type="password" id="new_password_confirm" name="new_password_confirm" minlength="12" required>
            <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
        </form>
    </div>

    <div class="card sec-card">
        <div class="sec-card-head">
            <h2>Verificación en dos pasos</h2>
            <?php if ($totpEnabled): ?>
                <span class="badge-status status-accepted">Activo</span>
            <?php endif; ?>
        </div>
        <?php if ($totpEnabled): ?>
            <p class="muted sec-card-desc">Te pedimos un código de tu app de autenticación cada vez que ingresás.</p>
            <form method="POST" action="/me/security/totp/disable" autocomplete="off" onsubmit="return confirm('¿Desactivar el 2FA?')">
                <?= CSRF::field() ?>
                <label for="password">Confirmá con tu contraseña para desactivar</label>
                <input type="password" id="password" name="password" required>
                <button type="submit" class="btn btn-danger">Desactivar 2FA</button>
            </form>
        <?php else: ?>
            <p class="muted sec-card-desc">Sumá una capa extra de protección con una app de autenticación (Google Authenticator, Authy, etc.).</p>
            <a class="btn btn-primary" href="/me/security/totp">Activar 2FA</a>
        <?php endif; ?>
    </div>
</div>
