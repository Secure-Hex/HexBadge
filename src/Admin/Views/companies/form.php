<?php
/**
 * @var array<string,mixed>|null $company
 * @var array<int,string>        $errors
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\Auth;

$isEdit = $company !== null && !empty($company['uuid']);
$action = $isEdit ? '/admin/companies/' . e((string) $company['uuid']) : '/admin/companies';
$val = static fn (string $k, string $d = '') => e((string) ($company[$k] ?? $d));
$isSuper = Auth::isSuperadmin();
$hasSmtpPass = !empty($company['smtp_password']);
?>
<div class="page-head"><h1><?= $isEdit ? e((string) $company['name']) : 'Nueva empresa' ?></h1></div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" style="max-width:580px">
    <?= CSRF::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="section" value="profile"><?php endif; ?>

    <h2 style="font-size:1.05rem;margin:.2rem 0 .6rem">Datos del emisor</h2>
    <p class="muted" style="margin-top:0">Estos datos son el emisor que muestran todos los badges y certificados de la empresa.</p>

    <label for="name">Nombre de la empresa / emisor *</label>
    <input type="text" id="name" name="name" maxlength="200" required value="<?= $val('name') ?>" placeholder="Ej: Cámara Chilena de IA">

    <label for="issuer_url">URL del emisor</label>
    <input type="url" id="issuer_url" name="issuer_url" value="<?= $val('issuer_url') ?>" placeholder="https://...">

    <label for="issuer_email">Email del emisor</label>
    <input type="email" id="issuer_email" name="issuer_email" maxlength="255" value="<?= $val('issuer_email') ?>" placeholder="contacto@empresa.cl">

    <label for="linkedin_org_id">LinkedIn Organization ID (opcional)</label>
    <input type="text" id="linkedin_org_id" name="linkedin_org_id" value="<?= $val('linkedin_org_id') ?>" placeholder="Ej: 1234567" inputmode="numeric">

    <label for="logo">Logo de la empresa (opcional) — PNG/JPG/SVG</label>
    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/svg+xml">
    <small class="muted">Si lo cargás, aparece en la página de verificación, arriba de la imagen de la acreditación. Subí uno nuevo para reemplazar el actual.</small>
    <?php if (!empty($company['logo_filename'])): ?>
        <img src="<?= e(logo_image_url((string) $company['logo_filename'])) ?>" alt="Logo actual" style="max-height:56px;max-width:200px;margin-top:8px;display:block;background:#fff;border:1px solid var(--border);border-radius:6px;padding:6px">
    <?php endif; ?>

    <?php if ($isSuper): ?>
        <label for="is_active">Estado</label>
        <select id="is_active" name="is_active">
            <option value="1" <?= ((int) ($company['is_active'] ?? 1) === 1) ? 'selected' : '' ?>>Activa</option>
            <option value="0" <?= ((int) ($company['is_active'] ?? 1) === 0) ? 'selected' : '' ?>>Inactiva</option>
        </select>
    <?php endif; ?>

    <?php if ($isEdit): ?>
        <button type="submit" class="btn btn-primary" style="margin-top:1rem">Guardar datos del emisor</button>
</form>

<form method="POST" action="<?= $action ?>" style="max-width:580px;margin-top:2rem;border-top:1px solid var(--border);padding-top:1.5rem">
    <?= CSRF::field() ?>
    <input type="hidden" name="section" value="smtp">
    <?php else: ?>
    <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--border)">
    <?php endif; ?>

    <h2 style="font-size:1.05rem;margin:.2rem 0 .6rem">Servidor de correo propio (SMTP)</h2>
    <p class="muted" style="margin-top:0">Opcional. Si lo dejás <strong>vacío</strong>, la empresa usa el SMTP global de la plataforma. Si lo completás, los correos de esta empresa salen por acá — sin afectar a las demás.<?= $isEdit ? ' Se guarda por separado de los datos del emisor.' : '' ?></p>

    <label for="smtp_host">Host SMTP</label>
    <input type="text" id="smtp_host" name="smtp_host" value="<?= $val('smtp_host') ?>" placeholder="smtp-relay.brevo.com (vacío = usar el global)">

    <div style="display:flex;gap:.8rem">
        <div style="flex:1">
            <label for="smtp_port">Puerto</label>
            <input type="number" id="smtp_port" name="smtp_port" value="<?= $val('smtp_port', '587') ?>" min="1" max="65535">
        </div>
        <div style="flex:1">
            <label for="smtp_encryption">Cifrado</label>
            <select id="smtp_encryption" name="smtp_encryption">
                <?php $enc = (string) ($company['smtp_encryption'] ?? 'tls'); ?>
                <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>Ninguno</option>
            </select>
        </div>
    </div>

    <label for="smtp_username">Usuario</label>
    <input type="text" id="smtp_username" name="smtp_username" value="<?= $val('smtp_username') ?>" autocomplete="off">

    <label for="smtp_password">Contraseña <?= $hasSmtpPass ? '(dejar vacío para mantener la actual)' : '' ?></label>
    <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="<?= $hasSmtpPass ? '••••••••' : '' ?>">

    <label for="smtp_from_address">Remitente (From)</label>
    <input type="email" id="smtp_from_address" name="smtp_from_address" value="<?= $val('smtp_from_address') ?>" placeholder="no-reply@empresa.cl">

    <label for="smtp_from_name">Nombre del remitente</label>
    <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= $val('smtp_from_name') ?>" placeholder="Ej: Cámara Chilena de IA">

    <button type="submit" class="btn btn-primary<?= $isEdit ? '' : ' btn-block' ?>" style="margin-top:1rem"><?= $isEdit ? 'Guardar configuración SMTP' : 'Crear empresa' ?></button>
</form>

<?php if ($isEdit): ?>
<form method="POST" action="/admin/companies/<?= e((string) $company['uuid']) ?>/smtp-test" style="max-width:580px;margin-top:1.2rem;display:flex;gap:.6rem;align-items:end">
    <?= CSRF::field() ?>
    <div style="flex:1">
        <label for="test_email">Probar el SMTP de esta empresa — enviar a:</label>
        <input type="email" id="test_email" name="test_email" required placeholder="vos@correo.com">
    </div>
    <button type="submit" class="btn">Enviar prueba</button>
</form>
<p class="muted" style="font-size:.82rem;max-width:580px">Guardá los cambios antes de probar. Si la empresa no tiene SMTP propio, la prueba usa el SMTP global.</p>
<?php endif; ?>
