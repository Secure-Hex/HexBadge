<?php
/**
 * Multi-select de empresas con búsqueda (dropdown + chips). Escala a muchas
 * empresas sin romper el layout. Envío NATIVO: los checkboxes reales viven en
 * el panel y postean company_ids[]; el JS (company-multiselect.js) maneja
 * abrir/cerrar, filtrar y las chips. Requiere JS (como el resto del panel).
 *
 * @var array<int,array<string,mixed>> $companies    Empresas asignables.
 * @var array<int,int>                 $selectedIds  Empresas marcadas.
 * @var string                         $label        Etiqueta (vacío = sin label).
 */
$selectedIds = $selectedIds ?? [];
$label = $label ?? 'Empresas (una o más)';
?>
<div class="ms" data-ms>
    <?php if ($label !== ''): ?><label class="ms-label"><?= e($label) ?></label><?php endif; ?>
    <button type="button" class="ms-trigger" data-ms-trigger aria-haspopup="listbox" aria-expanded="false">
        <span data-ms-count>Elegir empresas</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div class="ms-panel" data-ms-panel hidden>
        <input type="search" class="ms-search" data-ms-search placeholder="Buscar empresa..." autocomplete="off" aria-label="Buscar empresa">
        <div class="ms-list" data-ms-list role="listbox" aria-multiselectable="true">
            <?php foreach ($companies as $c): ?>
                <label class="ms-opt" data-ms-name="<?= e(mb_strtolower((string) $c['name'])) ?>">
                    <input type="checkbox" name="company_ids[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $selectedIds, true) ? 'checked' : '' ?>>
                    <span><?= e((string) $c['name']) ?></span>
                </label>
            <?php endforeach; ?>
            <p class="ms-empty" data-ms-empty hidden>Sin resultados</p>
        </div>
    </div>
    <div class="ms-chips" data-ms-chips></div>
</div>
