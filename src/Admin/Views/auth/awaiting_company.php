<?php
/**
 * Usuario issuer/admin creado desde Hermes que todavía no tiene empresa asignada.
 *
 * @var string $userName
 */
use HexBadge\Core\CSRF;
?>
<div class="auth-card">
    <div class="brand-row">
        <span class="brand-mark"><?= \HexBadge\Core\View::renderPartial('layout/securelogo') ?></span>
        <b>HexBadge</b>
    </div>
    <h1>Esperando asignación de empresa</h1>
    <p class="auth-subtitle">Hola<?= $userName !== '' ? ', ' . e($userName) : '' ?>.</p>
    <p>Tu cuenta ya está creada, pero aún no tiene una empresa asignada. Un superadministrador de HexBadge debe asignártela para que puedas usar el panel.</p>
    <p>Cuando te la asignen, vuelve a cargar esta página.</p>

    <a class="btn btn-primary btn-block" href="/awaiting-company">Volver a comprobar</a>
    <form method="POST" action="/logout" style="margin-top:.75rem">
        <?= CSRF::field() ?>
        <button type="submit" class="btn btn-block">Cerrar sesión</button>
    </form>
</div>
