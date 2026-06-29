<?php
/**
 * @var string              $token
 * @var string              $mode   'login' | 'register'
 * @var array<string,mixed> $badge
 * @var string              $email
 * @var string              $firstName
 * @var array<int,string>   $errors
 */
use HexBadge\Core\CSRF;

?>
<div class="auth-card" style="max-width:440px">
    <div style="text-align:center">
        <img src="<?= e(badge_image_url((string) $badge['image_filename'])) ?>" alt="" style="width:120px;height:120px;object-fit:contain">
        <h1 style="margin:.5rem 0 0">¡Ganaste un badge!</h1>
        <p class="muted"><strong><?= e((string) $badge['template_name']) ?></strong></p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($mode === 'register'): ?>
        <p>Creá tu cuenta para reclamar el badge a nombre de <strong><?= e($firstName) ?></strong>.</p>
    <?php else: ?>
        <p>Iniciá sesión para reclamar este badge.</p>
    <?php endif; ?>

    <form method="POST" action="/accept/<?= e($token) ?>" autocomplete="off">
        <?= CSRF::field() ?>

        <label>Email</label>
        <input type="email" value="<?= e($email) ?>" disabled>

        <?php if ($mode === 'register'): ?>
            <label for="password">Creá una contraseña (mín. 12 caracteres)</label>
            <input type="password" id="password" name="password" minlength="12" required autofocus>
            <label for="password_confirm">Repetir contraseña</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="12" required>
            <button type="submit" class="btn btn-primary btn-block">Crear cuenta y reclamar</button>
        <?php else: ?>
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required autofocus>
            <?php if (!empty($requiresTotp)): ?>
                <label for="code">Código 2FA (6 dígitos)</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required placeholder="000000" style="text-align:center;letter-spacing:4px">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block">Ingresar y reclamar</button>
        <?php endif; ?>
    </form>
</div>
