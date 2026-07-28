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

    <?php
    // Sin `required` en el archivo: con el modo diseño activo el navegador se
    // niega a enviar el formulario e intenta enfocar un campo oculto. La
    // obligatoriedad la valida el servidor según el modo.
    $imgMode = !empty($t['design_recipe']) ? 'design' : 'upload';
    $issued  = (int) ($t['badges_issued'] ?? 0);
    ?>
    <fieldset class="mode-set">
        <legend>Imagen de la acreditación <?= $isEdit ? '' : '*' ?></legend>

        <label class="mode-opt">
            <input type="radio" name="image_mode" value="upload" <?= $imgMode === 'upload' ? 'checked' : '' ?>>
            Subir una imagen
        </label>
        <div data-image-mode="upload" class="mode-body">
            <input type="file" id="image" name="image" accept="image/png,image/jpeg,image/svg+xml">
            <small class="muted">PNG, JPG o SVG, máximo 2MB.<?= $isEdit ? ' Dejalo vacío para mantener la actual.' : '' ?></small>
            <?php if ($isEdit && !empty($t['image_filename']) && $imgMode === 'upload'): ?>
                <img src="<?= e(badge_image_url((string) $t['image_filename'])) ?>" alt="Imagen actual"
                     style="width:80px;margin-top:8px;border-radius:6px">
            <?php endif; ?>
        </div>

        <label class="mode-opt">
            <input type="radio" name="image_mode" value="design" <?= $imgMode === 'design' ? 'checked' : '' ?>>
            Diseñarla acá
        </label>
        <div data-image-mode="design" class="mode-body">
            <div class="bd-grid">
                <div class="bd-controls">
                    <label for="bd-shape">Forma</label>
                    <select id="bd-shape">
                        <?php foreach (\HexBadge\Services\BadgeDesignService::SHAPES as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="bd-row">
                        <span>
                            <label for="bd-fill">Color principal</label>
                            <input type="color" id="bd-fill" value="#1565d8">
                        </span>
                        <span>
                            <label for="bd-accent">Color del borde</label>
                            <input type="color" id="bd-accent" value="#0f1b2e">
                        </span>
                    </div>

                    <div class="bd-row">
                        <span>
                            <label for="bd-finish">Acabado</label>
                            <select id="bd-finish">
                                <?php foreach (\HexBadge\Services\BadgeDesignService::FINISHES as $k => $label): ?>
                                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                        <span>
                            <label for="bd-ring">Borde</label>
                            <select id="bd-ring">
                                <?php foreach (\HexBadge\Services\BadgeDesignService::RINGS as $k => $label): ?>
                                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </div>

                    <label class="bd-check"><input type="checkbox" id="bd-ribbon"> Mostrar el nivel en una cinta</label>
                    <label class="bd-check"><input type="checkbox" id="bd-logo"> Incluir el logo de la empresa</label>

                    <label for="bd-initials">Iniciales <span class="muted">(opcional, hasta 2)</span></label>
                    <input type="text" id="bd-initials" maxlength="2" placeholder="Ej: SH">

                    <label for="bd-title">Título</label>
                    <input type="text" id="bd-title" maxlength="40" placeholder="Ej: Fundamentos de Seguridad Web">

                    <label for="bd-level">Nivel <span class="muted">(opcional)</span></label>
                    <input type="text" id="bd-level" maxlength="24" placeholder="Ej: Asistente">
                </div>

                <figure class="bd-preview">
                    <img id="bd-preview" width="200" height="200" alt="Vista previa del diseño">
                    <figcaption class="muted">Así se va a ver la insignia.</figcaption>
                </figure>
            </div>
            <?php // En edición la empresa ya no es un select: se pasa acá para que la
                  // vista previa pueda pedir su logo. ?>
            <input type="hidden" name="design_recipe" id="bd-recipe"
                   data-company="<?= (int) ($t['company_id'] ?? 0) ?>"
                   value="<?= e((string) ($t['design_recipe'] ?? '')) ?>">
        </div>
    </fieldset>

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

    <?php
    $diplomaTemplates = $diplomaTemplates ?? [];
    $curLink = (int) ($t['certificate_template_id'] ?? 0);
    $curOwn  = $curLink === 0 && !empty($t['certificate_filename']);
    $curMode = $curLink > 0 ? 'template' : ($curOwn ? 'upload' : 'none');
    ?>
    <fieldset style="border:1px solid var(--border);border-radius:8px;padding:1rem;margin-top:1rem">
        <legend style="padding:0 .4rem;font-weight:600">Diploma / certificado</legend>

        <label class="cert-opt" style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0">
            <input type="radio" name="cert_mode" value="none" <?= $curMode === 'none' ? 'checked' : '' ?>> Sin diploma
        </label>

        <label class="cert-opt" style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0">
            <input type="radio" name="cert_mode" value="upload" <?= $curMode === 'upload' ? 'checked' : '' ?>> Subir una imagen propia y marcarla
        </label>
        <div data-cert-mode="upload" style="margin:.2rem 0 .6rem 1.7rem">
            <input type="file" id="certificate_image" name="certificate_image" accept="image/png,image/jpeg">
            <small class="muted" style="display:block">PNG/JPG, máx 8MB. Tras subirla marcás dónde van el nombre, el QR, la fecha y el ID. <?php if ($curOwn): ?><strong>Ya hay una imagen propia cargada</strong> (subí otra para reemplazarla).<?php endif; ?></small>
        </div>

        <label class="cert-opt" style="display:flex;gap:.5rem;align-items:center;margin:.3rem 0">
            <input type="radio" name="cert_mode" value="template" <?= $curMode === 'template' ? 'checked' : '' ?> <?= empty($diplomaTemplates) ? 'disabled' : '' ?>> Usar una plantilla de diploma guardada
        </label>
        <div data-cert-mode="template" style="margin:.2rem 0 .6rem 1.7rem">
            <?php if (empty($diplomaTemplates)): ?>
                <small class="muted">No hay plantillas guardadas. Creá una en <a href="/admin/diploma-templates">Plantillas de diplomas</a>.</small>
            <?php else: ?>
                <select name="certificate_template_id">
                    <?php foreach ($diplomaTemplates as $dt): ?>
                        <option value="<?= (int) $dt['id'] ?>" <?= $curLink === (int) $dt['id'] ? 'selected' : '' ?>><?= e((string) $dt['name']) ?><?= empty($dt['config']) ? ' (sin marcar)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted" style="display:block">Referencia viva: si editás la plantilla, cambian los diplomas de esta acreditación.</small>
            <?php endif; ?>
        </div>
    </fieldset>

    <button type="submit" class="btn btn-primary btn-block" style="margin-top:1rem"
            <?php if ($isEdit && $issued > 0): ?>data-confirm-image="Esta acreditación ya emitió <?= $issued ?> credencial<?= $issued === 1 ? '' : 'es' ?>. Al cambiar la imagen, todas pasan a mostrar el diseño nuevo, incluidas las ya verificadas. ¿Confirmás?"<?php endif; ?>><?= $isEdit ? 'Guardar cambios' : 'Crear template' ?></button>
</form>

<script src="<?= asset('js/template-form.js') ?>" defer></script>
<script src="<?= asset('js/badge-designer.js') ?>" defer></script>
