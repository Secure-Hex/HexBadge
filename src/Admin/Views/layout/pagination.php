<?php
/**
 * Control de paginación reutilizable.
 *
 * @var string             $baseUrl   Ruta base (sin query), ej. /admin/badges
 * @var int                $page      Página actual (1-based)
 * @var int                $totalPages
 * @var int                $total     Total de registros
 * @var array<string,mixed> $params   Filtros a preservar en los enlaces
 */
if (($totalPages ?? 1) <= 1) {
    // Igual mostramos el total cuando hay una sola página, como referencia.
    echo '<p class="muted" style="margin-top:1rem">' . (int) ($total ?? 0) . ' en total</p>';
    return;
}

$link = static function (int $p) use ($baseUrl, $params): string {
    $q = array_merge($params, ['page' => $p]);
    $q = array_filter($q, static fn ($v) => $v !== '' && $v !== null);
    return $baseUrl . '?' . http_build_query($q);
};
?>
<nav class="pagination" style="display:flex;align-items:center;gap:.8rem;margin-top:1.2rem;flex-wrap:wrap">
    <?php if ($page > 1): ?>
        <a class="btn btn-sm" href="<?= e($link($page - 1)) ?>">‹ Anterior</a>
    <?php else: ?>
        <span class="btn btn-sm" style="opacity:.45;pointer-events:none">‹ Anterior</span>
    <?php endif; ?>

    <span class="muted">Página <?= (int) $page ?> de <?= (int) $totalPages ?> · <?= (int) $total ?> en total</span>

    <?php if ($page < $totalPages): ?>
        <a class="btn btn-sm" href="<?= e($link($page + 1)) ?>">Siguiente ›</a>
    <?php else: ?>
        <span class="btn btn-sm" style="opacity:.45;pointer-events:none">Siguiente ›</span>
    <?php endif; ?>
</nav>
