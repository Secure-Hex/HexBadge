<?php
/**
 * Selector de "entradas por pantalla" para formularios de filtro (GET).
 *
 * @var int $perPage
 */
use HexBadge\Core\Controller;
?>
<div>
    <label for="per">Por página</label>
    <select id="per" name="per">
        <?php foreach (Controller::PER_PAGE_OPTIONS as $opt): ?>
            <option value="<?= $opt ?>" <?= ((int) ($perPage ?? 25) === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
    </select>
</div>
