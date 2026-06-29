<?php
/**
 * @var string|null $error
 * @var string      $oldEmail
 */
use HexBadge\Core\CSRF;
?>
<div class="auth-card">
    <h1>Ingresar</h1>
    <p class="auth-subtitle">Accedé a tus badges</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="off">
        <?= CSRF::field() ?>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($oldEmail) ?>" required autofocus>
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>
        <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
    </form>
</div>
