<?php
/**
 * @var array<int,array<string,mixed>> $templates
 * @var string                         $selected
 * @var array<int,string>              $errors
 * @var array<string,mixed>            $old
 */
use HexBadge\Core\CSRF;

$old = $old ?? [];
$o = static fn (string $k): string => e((string) ($old[$k] ?? ''));
?>
<h1>Emitir badge</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (empty($templates)): ?>
    <div class="alert alert-error">No hay templates activos. <a href="/admin/templates/new">Creá uno</a> y ponelo en estado "Activo".</div>
<?php else: ?>
<form method="POST" action="/admin/issue" style="max-width:560px">
    <?= CSRF::field() ?>

    <label for="template_id">Template *</label>
    <select id="template_id" name="template_id" required>
        <option value="">— Elegí un template —</option>
        <?php foreach ($templates as $t): ?>
            <option value="<?= e((string) $t['uuid']) ?>" <?= ($selected === $t['uuid'] || ($old['template_id'] ?? '') === $t['uuid']) ? 'selected' : '' ?>>
                <?= e((string) $t['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="email">Email del receptor *</label>
    <input type="email" id="email" name="email" maxlength="255" required value="<?= $o('email') ?>">

    <label for="first_name">Nombre *</label>
    <input type="text" id="first_name" name="first_name" maxlength="100" required value="<?= $o('first_name') ?>">

    <label for="last_name">Apellido *</label>
    <input type="text" id="last_name" name="last_name" maxlength="100" required value="<?= $o('last_name') ?>">

    <label for="locale">Idioma de notificación</label>
    <select id="locale" name="locale">
        <option value="es" <?= (($old['locale'] ?? 'es') === 'es') ? 'selected' : '' ?>>Español</option>
        <option value="en" <?= (($old['locale'] ?? '') === 'en') ? 'selected' : '' ?>>English</option>
    </select>

    <button type="submit" class="btn btn-primary btn-block">Emitir y notificar</button>
</form>
<?php endif; ?>
