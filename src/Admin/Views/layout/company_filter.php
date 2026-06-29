<?php
/**
 * Selector de empresa para filtrar listados (solo tiene sentido con >1 empresa,
 * es decir, para el superadmin). Para sub-admins (una sola empresa) no se muestra.
 *
 * @var array<int,array<string,mixed>> $companies
 * @var int|null                       $selected
 */
if (count($companies ?? []) <= 1) {
    return;
}
?>
<div>
    <label for="company">Empresa</label>
    <select id="company" name="company">
        <option value="">Todas las empresas</option>
        <?php foreach ($companies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) ($selected ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
