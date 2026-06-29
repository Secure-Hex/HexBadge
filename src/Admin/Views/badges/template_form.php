<?php
/**
 * @var array<string,mixed>|null      $template  Datos para edición o repoblado.
 * @var array<int,string>             $errors
 * @var array<int,array<string,mixed>> $companies Empresas disponibles para el usuario.
 */
use HexBadge\Core\CSRF;

$t = $template ?? [];
$isEdit = isset($t['uuid']) && $t['uuid'] !== '';
$action = $isEdit ? '/admin/templates/' . e((string) $t['uuid']) : '/admin/templates';
$val = static fn (string $k, string $d = ''): string => e((string) ($t[$k] ?? $d));
$tagsValue = (string) ($t['skills_tags_csv'] ?? $t['skills_tags'] ?? '');
$companies = $companies ?? [];
?>
<h1><?= $isEdit ? 'Editar template' : 'Nuevo template' ?></h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" style="max-width:640px">
    <?= CSRF::field() ?>

    <label for="name">Nombre del badge *</label>
    <input type="text" id="name" name="name" maxlength="200" required value="<?= $val('name') ?>">

    <label for="description">Descripción *</label>
    <textarea id="description" name="description" rows="3" required><?= $val('description') ?></textarea>

    <label for="criteria_text">Criterios de obtención *</label>
    <textarea id="criteria_text" name="criteria_text" rows="3" required><?= $val('criteria_text') ?></textarea>

    <label for="criteria_url">URL de criterios (opcional)</label>
    <input type="url" id="criteria_url" name="criteria_url" value="<?= $val('criteria_url') ?>">

    <label for="image">Imagen del badge <?= $isEdit ? '(dejar vacío para mantener la actual)' : '*' ?> — PNG/JPG/SVG, máx 2MB</label>
    <input type="file" id="image" name="image" accept="image/png,image/jpeg,image/svg+xml" <?= $isEdit ? '' : 'required' ?>>
    <?php if ($isEdit && !empty($t['image_filename'])): ?>
        <img src="<?= e(badge_image_url((string) $t['image_filename'])) ?>" alt="" style="width:80px;margin-top:8px;border-radius:6px">
    <?php endif; ?>

    <label for="skills_tags">Skills / etiquetas (separadas por coma)</label>
    <input type="text" id="skills_tags" name="skills_tags" value="<?= e($tagsValue) ?>" placeholder="pentesting, OWASP, web security">

    <?php if ($isEdit): ?>
        <label>Empresa emisora</label>
        <p class="muted" style="margin-top:-.2rem"><strong><?= e((string) ($t['company_name'] ?? '—')) ?></strong> — los datos del emisor (nombre, URL, email, LinkedIn) se editan en <a href="/admin/companies">Empresas</a>.</p>
    <?php elseif (count($companies) > 1): ?>
        <label for="company_id">Empresa emisora *</label>
        <select id="company_id" name="company_id" required>
            <option value="">— Elegí una empresa —</option>
            <?php foreach ($companies as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= ((int) ($t['company_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="muted" style="display:block;margin-top:-.3rem">El badge se emite a nombre de esta empresa. Los datos del emisor se gestionan en <a href="/admin/companies">Empresas</a>.</small>
    <?php elseif (count($companies) === 1): ?>
        <p class="muted">Emisor: <strong><?= e((string) $companies[0]['name']) ?></strong></p>
    <?php endif; ?>

    <label for="expires_days">Días de expiración (vacío = no expira)</label>
    <input type="number" id="expires_days" name="expires_days" min="1" max="3650" value="<?= $val('expires_days') ?>">

    <label for="state">Estado</label>
    <select id="state" name="state">
        <?php foreach (['draft' => 'Borrador', 'active' => 'Activo', 'archived' => 'Archivado'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= (($t['state'] ?? 'draft') === $k) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>

    <label for="is_public">Visibilidad</label>
    <select id="is_public" name="is_public">
        <option value="1" <?= ((int) ($t['is_public'] ?? 1) === 1) ? 'selected' : '' ?>>Público</option>
        <option value="0" <?= ((int) ($t['is_public'] ?? 1) === 0) ? 'selected' : '' ?>>Privado</option>
    </select>

    <label for="certificate_image">Plantilla de certificado / diploma (opcional) — imagen PNG/JPG, máx 8MB</label>
    <input type="file" id="certificate_image" name="certificate_image" accept="image/png,image/jpeg">
    <small class="muted" style="display:block;margin-top:-.3rem">Distinta de la imagen del badge. Tras subirla, vas a poder marcar dónde van el nombre, el QR, la fecha y el ID. <?php if ($isEdit && !empty($t['certificate_filename'])): ?><strong>Ya hay una plantilla cargada</strong> (subí otra para reemplazarla).<?php endif; ?></small>

    <button type="submit" class="btn btn-primary btn-block"><?= $isEdit ? 'Guardar cambios' : 'Crear template' ?></button>
</form>
