<?php
/**
 * @var array<string,mixed> $earner
 * @var array<int,string>   $errors
 */
use HexBadge\Core\CSRF;

$e   = $earner;
$val = static fn (string $k): string => e((string) ($e[$k] ?? ''));
$initial = strtoupper(mb_substr((string) ($e['display_name'] ?? ($e['first_name'] ?? '?')), 0, 1));
?>
<div class="pf-wrap">
    <div class="pf-head">
        <h1>Mi perfil</h1>
        <p class="muted">Así te ven las personas que reciben o verifican tus credenciales.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" action="/me/profile" enctype="multipart/form-data" class="card pf-card">
        <?= CSRF::field() ?>

        <!-- Vista previa tipo perfil -->
        <div class="pf-preview">
            <div class="pf-preview-cover"<?php if (!empty($e['cover_filename'])): ?> style="background-image:url('<?= e(profile_image_url((string) $e['cover_filename'])) ?>')"<?php endif; ?>></div>
            <div class="pf-preview-avatar">
                <?php if (!empty($e['avatar_filename'])): ?>
                    <img src="<?= e(profile_image_url((string) $e['avatar_filename'])) ?>" alt="">
                <?php else: ?>
                    <span><?= e($initial) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fotos -->
        <section class="pf-section">
            <h2>Fotos</h2>
            <div class="field-grid">
                <div>
                    <label for="avatar">Foto de perfil <span class="muted">· PNG o JPG</span></label>
                    <input type="file" id="avatar" name="avatar" accept="image/png,image/jpeg">
                    <?php if (!empty($e['avatar_filename'])): ?>
                        <label class="remove-check"><input type="checkbox" name="remove_avatar" value="1"> Quitar foto actual</label>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="cover">Foto de portada <span class="muted">· PNG o JPG</span></label>
                    <input type="file" id="cover" name="cover" accept="image/png,image/jpeg">
                    <?php if (!empty($e['cover_filename'])): ?>
                        <label class="remove-check"><input type="checkbox" name="remove_cover" value="1"> Quitar portada actual</label>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Datos personales -->
        <section class="pf-section">
            <h2>Datos personales</h2>
            <label for="email">Email</label>
            <input type="email" id="email" value="<?= $val('email') ?>" disabled>
            <div class="field-grid">
                <div>
                    <label for="first_name">Nombre</label>
                    <input type="text" id="first_name" name="first_name" maxlength="100" required value="<?= $val('first_name') ?>">
                </div>
                <div>
                    <label for="last_name">Apellido</label>
                    <input type="text" id="last_name" name="last_name" maxlength="100" required value="<?= $val('last_name') ?>">
                </div>
            </div>
        </section>

        <!-- Bio -->
        <section class="pf-section">
            <h2>Sobre vos</h2>
            <label for="profile_bio">Bio <span class="muted">· opcional</span></label>
            <textarea id="profile_bio" name="profile_bio" rows="4" maxlength="1000" placeholder="Contá en qué te especializás…"><?= $val('profile_bio') ?></textarea>
        </section>

        <!-- Redes y enlaces -->
        <section class="pf-section">
            <h2>Redes y enlaces</h2>
            <?php foreach (social_networks() as $net): ?>
                <label for="<?= e($net['key']) ?>"><?= e($net['label']) ?></label>
                <div class="input-icon" style="--brand:<?= e($net['brand']) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $net['icon'] ?>"/></svg>
                    <input type="url" id="<?= e($net['key']) ?>" name="<?= e($net['key']) ?>" placeholder="https://…" value="<?= $val($net['key']) ?>">
                </div>
            <?php endforeach; ?>
        </section>

        <button type="submit" class="btn btn-primary btn-block">Guardar perfil</button>
    </form>
</div>
<script src="<?= asset('js/profile-preview.js') ?>" defer></script>
