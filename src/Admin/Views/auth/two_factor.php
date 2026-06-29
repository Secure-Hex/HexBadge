<?php
/**
 * @var string|null $error
 */
use HexBadge\Core\CSRF;
?>
<div class="auth-card">
    <h1>Verificación 2FA</h1>
    <p class="auth-subtitle">Ingresá el código de tu app de autenticación</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login/2fa" autocomplete="off">
        <?= CSRF::field() ?>
        <label for="code">Código de 6 dígitos</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
               placeholder="000000" style="font-size:1.4rem;text-align:center;letter-spacing:6px" autofocus>
        <button type="submit" class="btn btn-primary btn-block">Verificar</button>
    </form>
</div>
