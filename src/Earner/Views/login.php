<?php
/**
 * @var string|null $error
 * @var string      $oldEmail
 */
use HexBadge\Core\CSRF;

$reset = $reset ?? false;
?>
<div class="auth-card">
    <h1>Ingresar</h1>
    <p class="auth-subtitle">Accedé a tus badges</p>

    <?php if ($reset): ?>
        <div class="alert alert-success">Tu contraseña fue actualizada. Ya podés ingresar.</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="off">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($oldEmail) ?>" required autofocus>
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>
        <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
    </form>
    <p class="auth-alt"><a href="/forgot-password">¿Olvidaste tu contraseña?</a></p>
</div>
