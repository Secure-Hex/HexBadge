<?php
/**
 * Formulario de login.
 *
 * @var string|null $error
 * @var string|null $oldEmail
 */
$error    = $error ?? null;
$oldEmail = $oldEmail ?? '';
?>
<div class="auth-card">
    <div class="brand-row">
        <span class="brand-mark"><?= \HexBadge\Core\View::renderPartial('layout/securelogo') ?></span>
        <b>HexBadge</b>
    </div>
    <h1>Iniciar sesión</h1>
    <p class="auth-subtitle">Panel de administración · SecureHex</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="off">
        <?= \HexBadge\Core\CSRF::field() ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($oldEmail) ?>"
               required maxlength="255" autofocus>

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
    </form>
</div>
