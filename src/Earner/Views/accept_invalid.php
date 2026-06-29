<?php
/**
 * @var bool $revoked
 * @var bool $expired
 */
$revoked = $revoked ?? false;
$expired = $expired ?? false;
?>
<h1>No se pudo aceptar el badge</h1>
<?php if ($revoked): ?>
    <div class="alert alert-error">Este badge fue revocado por el emisor.</div>
<?php elseif ($expired): ?>
    <div class="alert alert-error">El enlace de aceptación expiró. Pedí al emisor que te reenvíe la invitación.</div>
<?php else: ?>
    <div class="alert alert-error">El enlace no es válido. Revisá que hayas copiado la URL completa del email.</div>
<?php endif; ?>
