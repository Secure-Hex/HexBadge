<?php
/**
 * Alta/edición de una plantilla de diploma (nombre + imagen). El marcado de
 * posiciones se hace después, en la pantalla de marcado.
 *
 * @var array<string,mixed>|null       $diploma
 * @var array<int,string>              $errors
 * @var array<int,array<string,mixed>> $companies
 */
use HexBadge\Core\CSRF;

$isEdit = !empty($diploma['uuid']);
$d      = $diploma ?? [];
$action = $isEdit ? '/admin/diploma-templates/' . rawurlencode((string) $d['uuid']) : '/admin/diploma-templates';
?>
<h1><?= $isEdit ? 'Editar plantilla de diploma' : 'Nueva plantilla de diploma' ?></h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="<?= e($action) ?>" enctype="multipart/form-data" style="max-width:560px">
    <?= CSRF::field() ?>

    <label for="name">Nombre *</label>
    <input type="text" id="name" name="name" required maxlength="150"
           value="<?= e((string) ($d['name'] ?? '')) ?>" placeholder="Ej: Diploma genérico 2026">

    <?php if (!$isEdit && count($companies) > 1): ?>
        <label for="company_id">Empresa</label>
        <select id="company_id" name="company_id">
            <option value="">— Global (solo superadmin) —</option>
            <?php foreach ($companies as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <label for="image">Imagen de la plantilla <?= $isEdit ? '(dejar vacío para mantener)' : '*' ?> — PNG/JPG, máx 8MB</label>
    <input type="file" id="image" name="image" accept="image/png,image/jpeg" <?= $isEdit ? '' : 'required' ?>>
    <?php if ($isEdit && !empty($d['image_filename'])): ?>
        <img src="<?= e(public_url('uploads/certificates/' . (string) $d['image_filename'])) ?>" alt=""
             style="width:180px;margin-top:8px;border-radius:6px;border:1px solid var(--border)">
        <small class="muted" style="display:block;margin-top:.2rem">Subir otra imagen la reemplaza y obliga a volver a marcar las posiciones.</small>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary btn-block" style="margin-top:1rem"><?= $isEdit ? 'Guardar' : 'Crear y marcar' ?></button>
</form>
