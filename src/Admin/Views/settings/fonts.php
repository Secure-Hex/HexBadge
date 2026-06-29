<?php
/**
 * Gestión de tipografías para certificados.
 *
 * @var array<int,array<string,mixed>> $fonts
 * @var array<int,string>              $errors
 */
use HexBadge\Core\CSRF;
?>
<div class="page-head">
    <h1>Tipografías</h1>
</div>
<p class="muted">Fuentes disponibles al marcar los certificados. Podés subir tus propias <strong>.ttf</strong> o <strong>.otf</strong> si las incluidas no te convencen.</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:520px;margin-bottom:1.5rem">
    <h2 style="margin-top:0">Subir una fuente</h2>
    <form method="POST" action="/admin/fonts" enctype="multipart/form-data">
        <?= CSRF::field() ?>
        <label for="name">Nombre visible</label>
        <input type="text" id="name" name="name" maxlength="100" required placeholder="Ej: Montserrat (negrita)">

        <label for="font">Archivo (.ttf / .otf, máx 5MB)</label>
        <input type="file" id="font" name="font" accept=".ttf,.otf,font/ttf,font/otf,application/font-sfnt" required>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:.8rem">Subir fuente</button>
    </form>
</div>

<table class="table">
    <thead><tr><th>Nombre</th><th>Origen</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fonts as $f): ?>
        <tr>
            <td><?= e((string) $f['name']) ?></td>
            <td><?= ((int) $f['is_builtin'] === 1) ? '<span class="badge-status status-accepted">incluida</span>' : '<span class="badge-status status-pending">subida</span>' ?></td>
            <td style="text-align:right">
                <?php if ((int) $f['is_builtin'] === 0): ?>
                    <form method="POST" action="/admin/fonts/<?= (int) $f['id'] ?>/delete" style="display:inline"
                          onsubmit="return confirm('¿Eliminar esta fuente? Los certificados que la usen volverán a la fuente por defecto.')">
                        <?= CSRF::field() ?>
                        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                    </form>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
