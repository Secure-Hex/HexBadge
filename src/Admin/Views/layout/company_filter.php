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
// El superadmin puede ver "todas las empresas" (filtro nulo). Un sub-admin con
// varias empresas opera en UNA a la vez, así que no se le ofrece esa opción.
$allowAll = \HexBadge\Core\Auth::isSuperadmin();
?>
<div>
    <label for="company">Empresa</label>
    <select id="company" name="company">
        <?php if ($allowAll): ?><option value="">Todas las empresas</option><?php endif; ?>
        <?php foreach ($companies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) ($selected ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
