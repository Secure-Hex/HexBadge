<?php
/**
 * @var array<string,mixed> $earner
 * @var array<int,string>   $errors
 */
use HexBadge\Core\CSRF;

$e = $earner;
$val = static fn (string $k): string => e((string) ($e[$k] ?? ''));
?>
<h1>Mi perfil</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="/me/profile" style="max-width:520px">
    <?= CSRF::field() ?>
    <label>Email</label>
    <input type="email" value="<?= $val('email') ?>" disabled>

    <label for="first_name">Nombre</label>
    <input type="text" id="first_name" name="first_name" maxlength="100" required value="<?= $val('first_name') ?>">

    <label for="last_name">Apellido</label>
    <input type="text" id="last_name" name="last_name" maxlength="100" required value="<?= $val('last_name') ?>">

    <label for="profile_bio">Bio (opcional)</label>
    <textarea id="profile_bio" name="profile_bio" rows="3" maxlength="1000"><?= $val('profile_bio') ?></textarea>

    <label for="profile_url">Sitio / LinkedIn (opcional)</label>
    <input type="url" id="profile_url" name="profile_url" value="<?= $val('profile_url') ?>">

    <button type="submit" class="btn btn-primary btn-block">Guardar perfil</button>
</form>
