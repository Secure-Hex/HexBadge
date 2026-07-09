<?php
/**
 * @var array<string,mixed> $earner
 * @var array<int,string>   $errors
 */
use HexBadge\Core\CSRF;

$e   = $earner;
$val = static fn (string $k): string => e((string) ($e[$k] ?? ''));
$initial  = strtoupper(mb_substr((string) ($e['display_name'] ?? ($e['first_name'] ?? '?')), 0, 1));
$fullName = trim((string) ($e['first_name'] ?? '') . ' ' . (string) ($e['last_name'] ?? ''));
$fullName = $fullName !== '' ? $fullName : (string) ($e['display_name'] ?? 'Tu nombre');
?>
<div class="pf-wrap">
    <div class="pf-head">
        <h1>Mi perfil</h1>
        <p class="muted">Así te ven las personas que reciben o verifican tus credenciales.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" action="/me/profile" enctype="multipart/form-data" class="pf-layout">
        <?= CSRF::field() ?>

        <!-- Columna lateral: vista previa + carga de fotos -->
        <aside class="pf-aside">
            <div class="card pf-preview-card">
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
                <div class="pf-preview-id">
                    <strong><?= e($fullName) ?></strong>
                    <span class="muted"><?= $val('email') ?></span>
                </div>
            </div>

            <div class="card pf-photos">
                <h2 class="pf-card-title">Fotos</h2>
                <div class="pf-photo-field">
                    <span class="pf-photo-label">Foto de perfil</span>
                    <input type="file" id="avatar" name="avatar" accept="image/png,image/jpeg" class="file-input-hidden">
                    <label class="file-drop" for="avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                        <span class="file-drop-text">Elegir archivo <span class="muted">· PNG o JPG</span></span>
                    </label>
                    <?php if (!empty($e['avatar_filename'])): ?>
                        <button type="submit" form="rm-avatar" class="btn-remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                            Quitar foto actual
                        </button>
                    <?php endif; ?>
                </div>
                <div class="pf-photo-field">
                    <span class="pf-photo-label">Foto de portada</span>
                    <input type="file" id="cover" name="cover" accept="image/png,image/jpeg" class="file-input-hidden">
                    <label class="file-drop" for="cover">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                        <span class="file-drop-text">Elegir archivo <span class="muted">· PNG o JPG</span></span>
                    </label>
                    <?php if (!empty($e['cover_filename'])): ?>
                        <button type="submit" form="rm-cover" class="btn-remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                            Quitar portada actual
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- Columna principal: datos en tarjetas -->
        <div class="pf-main">
            <div class="card pf-fields">
                <h2 class="pf-card-title">Datos personales</h2>
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
            </div>

            <div class="card pf-fields">
                <h2 class="pf-card-title">Sobre vos</h2>
                <label for="profile_bio">Bio <span class="muted">· opcional</span></label>
                <textarea id="profile_bio" name="profile_bio" rows="5" maxlength="1000" placeholder="Contá en qué te especializás…"><?= $val('profile_bio') ?></textarea>
            </div>

            <div class="card pf-fields pf-card--wide">
                <h2 class="pf-card-title">Redes y enlaces</h2>
                <div class="pf-social">
                    <?php foreach (social_networks() as $net): ?>
                        <div class="pf-social-item">
                            <label for="<?= e($net['key']) ?>"><?= e($net['label']) ?></label>
                            <div class="input-icon" style="--brand:<?= e($net['brand']) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $net['icon'] ?>"/></svg>
                                <input type="url" id="<?= e($net['key']) ?>" name="<?= e($net['key']) ?>" placeholder="https://…" value="<?= $val($net['key']) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pf-actions pf-card--wide">
                <button type="submit" class="btn btn-primary">Guardar perfil</button>
            </div>
        </div>
    </form>

    <?php if (!empty($e['avatar_filename'])): ?>
    <form id="rm-avatar" method="POST" action="/me/profile/photo/delete" class="pf-hidden-form">
        <?= CSRF::field() ?><input type="hidden" name="field" value="avatar">
    </form>
    <?php endif; ?>
    <?php if (!empty($e['cover_filename'])): ?>
    <form id="rm-cover" method="POST" action="/me/profile/photo/delete" class="pf-hidden-form">
        <?= CSRF::field() ?><input type="hidden" name="field" value="cover">
    </form>
    <?php endif; ?>
</div>
<script src="<?= asset('js/profile-preview.js') ?>" defer></script>
