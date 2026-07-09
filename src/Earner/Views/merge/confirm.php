<?php
/**
 * @var string                   $token
 * @var array<string,mixed>      $merge
 * @var array<string,mixed>|null $target
 * @var array<string,mixed>|null $source
 */
use HexBadge\Core\CSRF;

$sourceEmail = (string) $merge['source_email'];
$targetName  = trim((string) ($target['display_name'] ?? '')) ?: (string) ($target['email'] ?? 'tu cuenta');
$targetEmail = (string) ($target['email'] ?? '');

$textFields = [
    'profile_bio'   => 'Bio',
    'profile_url'   => 'Sitio web',
    'linkedin_url'  => 'LinkedIn',
    'instagram_url' => 'Instagram',
    'x_url'         => 'X',
    'github_url'    => 'GitHub',
];
?>
<div class="merge-wrap">
    <div class="card merge-card">
        <h1>Unir mis acreditaciones</h1>
        <p class="muted">
            Vas a unir las acreditaciones enviadas a <strong><?= e($sourceEmail) ?></strong>
            con la wallet de <strong><?= e($targetName) ?></strong><?= $targetEmail !== '' ? ' (' . e($targetEmail) . ')' : '' ?>.
            Ese correo quedará vinculado a esta wallet y seguirá recibiendo futuras acreditaciones.
        </p>

        <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" action="/me/merge/<?= e($token) ?>">
            <?= CSRF::field() ?>

            <?php if ($source !== null): ?>
                <div class="merge-note">
                    Elegí qué datos de perfil conservar. Por defecto se mantiene el de
                    <strong><?= e($targetName) ?></strong>; marcá una casilla para usar el del otro correo.
                    La contraseña y el 2FA de tu cuenta actual no cambian.
                </div>
                <div class="merge-fields">
                    <?php if (trim((string) ($source['display_name'] ?? '')) !== '' && ($source['display_name'] ?? '') !== ($target['display_name'] ?? '')): ?>
                        <label class="merge-field">
                            <input type="checkbox" name="use_name" value="1">
                            <span class="merge-field-label">Nombre</span>
                            <span class="merge-vs"><span class="muted"><?= e((string) ($target['display_name'] ?? '—')) ?></span> → <strong><?= e((string) $source['display_name']) ?></strong></span>
                        </label>
                    <?php endif; ?>

                    <?php foreach ($textFields as $k => $lbl): $sv = trim((string) ($source[$k] ?? '')); if ($sv === '') continue; ?>
                        <label class="merge-field">
                            <input type="checkbox" name="use_<?= e($k) ?>" value="1">
                            <span class="merge-field-label"><?= e($lbl) ?></span>
                            <span class="merge-vs"><span class="muted"><?= e(trim((string) ($target[$k] ?? '')) !== '' ? (string) $target[$k] : '—') ?></span> → <strong><?= e($sv) ?></strong></span>
                        </label>
                    <?php endforeach; ?>

                    <?php foreach (['avatar_filename' => 'Foto de perfil', 'cover_filename' => 'Foto de portada'] as $k => $lbl): if (empty($source[$k])) continue; ?>
                        <label class="merge-field">
                            <input type="checkbox" name="use_<?= e($k) ?>" value="1">
                            <span class="merge-field-label"><?= e($lbl) ?></span>
                            <span class="merge-vs">
                                <?php if (!empty($target[$k])): ?><img class="merge-thumb" src="<?= e(profile_image_url((string) $target[$k])) ?>" alt=""><?php else: ?><span class="muted">—</span><?php endif; ?>
                                →
                                <img class="merge-thumb" src="<?= e(profile_image_url((string) $source[$k])) ?>" alt="">
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="merge-note">
                    Este correo no tiene una cuenta con perfil propio: solo se vinculará a tu wallet
                    (y sus acreditaciones pendientes pasarán a ella).
                </div>
            <?php endif; ?>

            <?php if (!empty($needsPassword)): ?>
                <div class="merge-auth">
                    <p class="merge-auth-title">Confirmá que esta cuenta es tuya</p>
                    <label for="merge_pw">Contraseña de <?= e($sourceEmail) ?></label>
                    <input type="password" id="merge_pw" name="password" required autocomplete="current-password">
                    <?php if (!empty($needs2fa)): ?>
                        <label for="merge_totp">Código de verificación (2FA)</label>
                        <input type="text" id="merge_totp" name="totp" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code" placeholder="123456">
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block">Unir wallets</button>
        </form>
    </div>
</div>
