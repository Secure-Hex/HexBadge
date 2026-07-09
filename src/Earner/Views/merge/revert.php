<?php
/**
 * @var string              $token
 * @var array<string,mixed> $merge
 */
use HexBadge\Core\CSRF;
?>
<div class="merge-wrap">
    <div class="card merge-card merge-invalid">
        <h1>¿Deshacer la unión?</h1>
        <p class="muted">
            Vamos a separar de nuevo las acreditaciones del correo
            <strong><?= e((string) $merge['source_email']) ?></strong> en su propia wallet.
            Podés volver a unirlas cuando quieras.
        </p>
        <form method="POST" action="/me/merge/revert/<?= e($token) ?>">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-danger btn-block">Sí, deshacer la unión</button>
        </form>
        <a class="btn btn-ghost" href="/login" style="margin-top:.6rem">No, mantenerlas unidas</a>
    </div>
</div>
