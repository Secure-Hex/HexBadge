<?php
/**
 * @var array<string,mixed>|null $user
 * @var bool                     $totpEnabled
 * @var array<int,string>        $errors
 */
use HexBadge\Core\CSRF;
?>
<h1>Mi cuenta</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<section style="max-width:480px">
    <h2>Cambiar contraseña</h2>
    <form method="POST" action="/admin/account/password" autocomplete="off">
        <?= CSRF::field() ?>
        <label for="current_password">Contraseña actual</label>
        <input type="password" id="current_password" name="current_password" required>
        <label for="new_password">Nueva contraseña (mín. 12 caracteres)</label>
        <input type="password" id="new_password" name="new_password" minlength="12" required>
        <label for="new_password_confirm">Repetir nueva contraseña</label>
        <input type="password" id="new_password_confirm" name="new_password_confirm" minlength="12" required>
        <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
    </form>
</section>

<section style="max-width:480px">
    <h2>Verificación en dos pasos (2FA)</h2>
    <?php if ($totpEnabled): ?>
        <p><span class="badge-status status-accepted">Activo</span> Tu cuenta pide un código TOTP al ingresar.</p>
        <form method="POST" action="/admin/account/totp/disable" autocomplete="off"
              onsubmit="return confirm('¿Desactivar el 2FA?')">
            <?= CSRF::field() ?>
            <label for="password">Confirmá con tu contraseña para desactivar</label>
            <input type="password" id="password" name="password" required>
            <button type="submit" class="btn btn-danger">Desactivar 2FA</button>
        </form>
    <?php else: ?>
        <p class="muted">Sumá una capa extra de seguridad con una app de autenticación (Google Authenticator, Authy, 1Password…).</p>
        <a class="btn btn-primary" href="/admin/account/totp">Activar 2FA</a>
    <?php endif; ?>
</section>
