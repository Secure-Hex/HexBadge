<?php
/**
 * Fragmento de resultados del buscador de personas (autocompletar de la wallet).
 * Sin layout: lo inyecta search.js en el dropdown.
 *
 * @var string                          $query
 * @var array<int,array<string,mixed>>  $results
 */
if ($query === '') {
    return; // sin query => fragmento vacío => dropdown oculto
}
?>
<?php if (empty($results)): ?>
    <p class="ps-empty">Sin coincidencias para “<?= e($query) ?>”.</p>
<?php else: ?>
    <?php foreach ($results as $p): ?>
        <?php $initial = strtoupper(mb_substr((string) $p['display_name'], 0, 1)); ?>
        <a class="person-card" href="/earner/<?= e((string) $p['uuid']) ?>">
            <span class="person-avatar">
                <?php if (!empty($p['avatar_filename'])): ?>
                    <img src="<?= e(profile_image_url((string) $p['avatar_filename'])) ?>" alt="">
                <?php else: ?>
                    <span><?= e($initial) ?></span>
                <?php endif; ?>
            </span>
            <span class="person-name"><?= e((string) $p['display_name']) ?></span>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
