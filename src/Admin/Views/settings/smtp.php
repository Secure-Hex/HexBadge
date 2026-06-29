<?php
/**
 * @var array<string,string> $smtp
 * @var bool                 $hasPassword
 * @var array<int,string>    $errors
 */
use HexBadge\Core\CSRF;

$val = static fn (string $k, string $d = ''): string => e((string) ($smtp[$k] ?? $d));
?>
<h1>Configuración SMTP</h1>
<p class="muted">Configurá el servidor de correo para que se envíen las invitaciones y notificaciones de badges.</p>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="/admin/settings" style="max-width:560px">
    <?= CSRF::field() ?>

    <label for="smtp_host">Servidor SMTP (host)</label>
    <input type="text" id="smtp_host" name="smtp_host" value="<?= $val('smtp_host') ?>" placeholder="smtp.gmail.com">

    <label for="smtp_port">Puerto</label>
    <input type="number" id="smtp_port" name="smtp_port" value="<?= $val('smtp_port', '587') ?>" min="1" max="65535">

    <label for="smtp_encryption">Cifrado</label>
    <select id="smtp_encryption" name="smtp_encryption">
        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Sin cifrado'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= (($smtp['smtp_encryption'] ?? 'tls') === $k) ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
    </select>

    <label for="smtp_username">Usuario</label>
    <input type="text" id="smtp_username" name="smtp_username" value="<?= $val('smtp_username') ?>" autocomplete="off">

    <label for="smtp_password">Contraseña <?= $hasPassword ? '(dejar vacío para mantener la actual)' : '' ?></label>
    <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="<?= $hasPassword ? '••••••••' : '' ?>">

    <label for="smtp_from_address">Remitente (From)</label>
    <input type="email" id="smtp_from_address" name="smtp_from_address" value="<?= $val('smtp_from_address') ?>" placeholder="noreply@securehex.cl">

    <label for="smtp_from_name">Nombre del remitente</label>
    <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= $val('smtp_from_name', 'SecureHex Badges') ?>">

    <button type="submit" class="btn btn-primary btn-block">Guardar configuración</button>
</form>

<section style="max-width:560px">
    <h2>Probar envío</h2>
    <form method="POST" action="/admin/settings/test" style="display:flex;gap:.6rem;align-items:end">
        <?= CSRF::field() ?>
        <div style="flex:1">
            <label for="test_email">Enviar correo de prueba a</label>
            <input type="email" id="test_email" name="test_email" required placeholder="tu@email.com">
        </div>
        <button type="submit" class="btn">Enviar prueba</button>
    </form>
    <p class="muted" style="font-size:.85rem">Si no hay SMTP configurado, los correos se guardan como archivos en <code>storage/mail/</code>.</p>
</section>
