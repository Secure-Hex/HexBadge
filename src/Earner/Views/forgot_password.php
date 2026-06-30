<?php
/**
 * @var bool        $sent
 * @var string|null $error
 */
use HexBadge\Core\CSRF;
?>
<div class="auth-card">
    <h1>Recuperar contraseña</h1>

    <?php if ($sent): ?>
        <p class="auth-subtitle">Si hay una cuenta con ese email, te enviamos un enlace para restablecer tu contraseña. Revisá tu bandeja (y el spam). El enlace vence en 1 hora.</p>
        <a class="btn btn-block" href="/login">Volver al inicio</a>
    <?php else: ?>
        <p class="auth-subtitle">Ingresá tu email y te enviaremos un enlace para elegir una nueva contraseña.</p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="POST" action="/forgot-password" autocomplete="off">
            <?= CSRF::field() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>
            <button type="submit" class="btn btn-primary btn-block">Enviar enlace</button>
        </form>
        <p class="auth-alt"><a href="/login">Volver al inicio</a></p>
    <?php endif; ?>
</div>
