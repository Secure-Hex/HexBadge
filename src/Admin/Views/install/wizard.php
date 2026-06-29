<?php
/**
 * Asistente de instalación (página independiente, sin layout).
 *
 * @var string|null              $error
 * @var array<string,mixed>      $old
 * @var string                   $csrf
 * @var string                   $appName
 */
$old = $old ?? [];
$v = static fn (string $k, string $default = ''): string => e((string) ($old[$k] ?? $default));
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — <?= e($appName) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-wrap" style="place-items:start center">
<div class="auth-card" style="max-width:560px;margin-top:2.5rem">
    <div class="brand-row">
        <span class="brand-mark"><?= \HexBadge\Core\View::renderPartial('layout/securelogo') ?></span>
        <b>HexBadge</b>
    </div>
    <h1>Instalación</h1>
    <p class="auth-subtitle">Configurá la base de datos y la cuenta del administrador inicial. Esto se hace una sola vez.</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/install" autocomplete="off">
        <?= $csrf ?>

        <h2>Aplicación</h2>
        <label for="app_name">Nombre de la plataforma</label>
        <input type="text" id="app_name" name="app_name" value="<?= $v('app_name', 'HexBadge') ?>" maxlength="100" required>

        <label for="app_url">URL del panel de administración (https://...)</label>
        <input type="url" id="app_url" name="app_url" value="<?= $v('app_url') ?>" placeholder="https://admin.tudominio.cl" required>
        <small class="muted" style="display:block;margin-top:-.3rem">Solo para administración. No se expone públicamente.</small>

        <label for="app_earner_url">URL pública (verificación + portal de receptores)</label>
        <input type="url" id="app_earner_url" name="app_earner_url" value="<?= $v('app_earner_url') ?>" placeholder="https://badges.tudominio.cl">
        <small class="muted" style="display:block;margin-top:-.3rem">Dominio que ven las personas: verificación de badges, imágenes y wallet. Si se deja vacío, se usa el del panel.</small>

        <h2>Base de datos (MySQL)</h2>
        <label for="db_host">Host</label>
        <input type="text" id="db_host" name="db_host" value="<?= $v('db_host', 'db') ?>" required>

        <label for="db_port">Puerto</label>
        <input type="text" id="db_port" name="db_port" value="<?= $v('db_port', '3306') ?>" required>

        <label for="db_name">Nombre de la base</label>
        <input type="text" id="db_name" name="db_name" value="<?= $v('db_name', 'hexbadge') ?>" required>

        <label for="db_user">Usuario</label>
        <input type="text" id="db_user" name="db_user" value="<?= $v('db_user') ?>" required>

        <label for="db_pass">Contraseña</label>
        <input type="password" id="db_pass" name="db_pass" value="">

        <h2>Administrador</h2>
        <label for="admin_name">Nombre completo</label>
        <input type="text" id="admin_name" name="admin_name" value="<?= $v('admin_name') ?>" maxlength="100" required>

        <label for="admin_email">Email</label>
        <input type="email" id="admin_email" name="admin_email" value="<?= $v('admin_email') ?>" maxlength="255" required>

        <label for="admin_password">Contraseña (mín. 12 caracteres)</label>
        <input type="password" id="admin_password" name="admin_password" minlength="12" required>

        <label for="admin_password_confirm">Repetir contraseña</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="12" required>

        <button type="submit" class="btn btn-primary btn-block">Instalar HexBadge</button>
    </form>
    <p style="text-align:center;margin-top:1.25rem;font-size:.82rem;color:var(--muted)">
        <strong>HexBadge</strong> — una herramienta de
        <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a>
    </p>
</div>
</body>
</html>
