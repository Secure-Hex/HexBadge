<?php
/**
 * @var string            $secret
 * @var string            $uri
 * @var array<int,string> $errors
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\Totp;
?>
<h1>Activar verificación en dos pasos</h1>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div style="max-width:520px">
    <p><strong>1.</strong> Abrí tu app de autenticación (Google Authenticator, Authy, 1Password…) y agregá una cuenta nueva.</p>
    <p><strong>2.</strong> Escaneá este QR con tu app de autenticación:</p>

    <?php if (!empty($qrSvg)): ?>
        <div style="background:#fff;padding:12px;display:inline-block;border-radius:8px;width:220px"><?= $qrSvg /* SVG QR en PHP puro (QrEncoder) */ ?></div>
    <?php endif; ?>

    <p style="margin-top:.5rem">¿No podés escanear? Cargá esta clave manualmente:</p>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1rem;text-align:center">
        <code style="font-size:1.25rem;letter-spacing:2px"><?= e(Totp::formatSecret($secret)) ?></code>
    </div>
    <p style="margin-top:.5rem">Desde el móvil también podés tocar: <a href="<?= e($uri) ?>">Abrir en la app</a></p>

    <p style="margin-top:1.5rem"><strong>3.</strong> Ingresá el código de 6 dígitos que muestra la app:</p>
    <form method="POST" action="/admin/account/totp" autocomplete="off" style="max-width:280px">
        <?= CSRF::field() ?>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
               placeholder="000000" style="font-size:1.4rem;text-align:center;letter-spacing:6px" autofocus>
        <button type="submit" class="btn btn-primary btn-block">Verificar y activar</button>
    </form>
    <p style="margin-top:1rem"><a href="/admin/account">Cancelar</a></p>
</div>
