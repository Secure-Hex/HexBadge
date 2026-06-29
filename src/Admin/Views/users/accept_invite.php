<?php
/**
 * @var string            $token
 * @var string            $email
 * @var string            $role
 * @var array<int,string> $errors
 */
use HexBadge\Core\CSRF;
?>
<div class="auth-card">
    <h1>Aceptar invitación</h1>
    <p class="auth-subtitle">Estás creando tu cuenta para <strong><?= e($email) ?></strong> (<?= e($role) ?>).</p>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" action="/accept-invite/<?= e($token) ?>" autocomplete="off">
        <?= CSRF::field() ?>
        <label for="name">Tu nombre completo</label>
        <input type="text" id="name" name="name" maxlength="100" required autofocus>

        <label for="password">Contraseña (mín. 12 caracteres)</label>
        <input type="password" id="password" name="password" minlength="12" required>

        <label for="password_confirm">Repetir contraseña</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="12" required>

        <button type="submit" class="btn btn-primary btn-block">Crear mi cuenta</button>
    </form>
</div>
