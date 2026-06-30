<?php
/**
 * @var string            $secret
 * @var string            $uri
 * @var string            $qrSvg
 * @var array<int,string> $errors
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\Totp;
?>
<div class="pf-wrap">
    <div class="pf-head">
        <h1>Activar verificación en dos pasos</h1>
        <p class="muted">Tres pasos para proteger tu cuenta con una app de autenticación.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card totp-card">
        <div class="totp-step">
            <span class="totp-num">1</span>
            <div class="totp-step-body">
                <h2>Abrí tu app de autenticación</h2>
                <p class="muted">Google Authenticator, Authy, 1Password… y agregá una cuenta nueva.</p>
            </div>
        </div>

        <div class="totp-step">
            <span class="totp-num">2</span>
            <div class="totp-step-body">
                <h2>Escaneá el código QR</h2>
                <?php if (!empty($qrSvg)): ?>
                    <div class="totp-qr"><?= $qrSvg /* SVG QR en PHP puro (QrEncoder) */ ?></div>
                <?php endif; ?>
                <p class="muted">¿No podés escanear? Cargá esta clave manualmente:</p>
                <div class="totp-secret"><code><?= e(Totp::formatSecret($secret)) ?></code></div>
                <p class="muted totp-mobile">En el móvil: <a href="<?= e($uri) ?>">abrir directo en la app</a></p>
            </div>
        </div>

        <div class="totp-step">
            <span class="totp-num">3</span>
            <div class="totp-step-body">
                <h2>Ingresá el código de 6 dígitos</h2>
                <form method="POST" action="/me/security/totp" autocomplete="off">
                    <?= CSRF::field() ?>
                    <input class="totp-code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
                           placeholder="000000" autofocus>
                    <button type="submit" class="btn btn-primary">Verificar y activar</button>
                </form>
            </div>
        </div>
    </div>

    <p class="totp-cancel"><a href="/me/security">Cancelar</a></p>
</div>
