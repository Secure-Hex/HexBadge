<?php
/**
 * Editar las empresas a las que un usuario (admin/issuer) tiene acceso.
 *
 * @var array<string,mixed>            $user
 * @var array<int,array<string,mixed>> $companies    Empresas que el editor puede asignar.
 * @var array<int,int>                 $assignedIds  Empresas actuales del usuario.
 * @var array<int,string>              $errors
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\View;
?>
<div class="page-head"><h1>Empresas de <?= e((string) $user['name']) ?></h1></div>
<p class="muted" style="margin-top:-.4rem"><?= e((string) $user['email']) ?> — rol <strong><?= e((string) $user['role']) ?></strong></p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<form method="POST" action="/admin/users/<?= e((string) $user['uuid']) ?>" style="max-width:480px">
    <?= CSRF::field() ?>
    <?= View::renderPartial('layout/company_multiselect', ['companies' => $companies, 'selectedIds' => $assignedIds, 'label' => 'Empresas con acceso (una o más)']) ?>
    <small class="muted" style="display:block">La primera marcada es su empresa primaria (dueña de sus API keys). El cambio aplica en su próximo inicio de sesión.</small>

    <div style="display:flex;gap:.6rem;margin-top:1rem">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <a class="btn" href="/admin/users">Cancelar</a>
    </div>
</form>
