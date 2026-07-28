<?php
/**
 * Página de resultados del directorio de personas. El desplegable del
 * autocompletar usa el fragmento search_results.php; esta es la versión
 * navegable de la misma búsqueda.
 *
 * @var string                         $query
 * @var array<int,array<string,mixed>> $results
 */
?>
<h1>Buscar personas</h1>

<form class="people-page-form" method="GET" action="/buscar" role="search">
    <label class="sr-only" for="q">Nombre de la persona</label>
    <input type="search" id="q" name="q" value="<?= e($query) ?>"
           placeholder="Nombre de la persona" autocomplete="off" autofocus>
    <button type="submit" class="btn btn-primary">Buscar</button>
</form>

<?php if ($query === ''): ?>
    <div class="empty-state">
        <p><strong>Buscá a alguien por su nombre.</strong></p>
        <p class="muted">Vas a ver su perfil público con las credenciales que aceptó.</p>
    </div>
<?php elseif (empty($results)): ?>
    <div class="empty-state">
        <p><strong>Sin coincidencias para «<?= e($query) ?>».</strong></p>
        <p class="muted">Probá con el nombre completo o con otra forma de escribirlo. Solo aparecen las personas con al menos una credencial aceptada.</p>
    </div>
<?php else: ?>
    <p class="muted people-page-count">
        <?= count($results) ?> <?= count($results) === 1 ? 'persona encontrada' : 'personas encontradas' ?>
    </p>
    <ul class="people-page-list">
        <?php foreach ($results as $p): ?>
            <?php $initial = strtoupper(mb_substr((string) $p['display_name'], 0, 1)); ?>
            <li>
                <a class="person-card" href="/earner/<?= e((string) $p['uuid']) ?>">
                    <span class="person-avatar">
                        <?php if (!empty($p['avatar_filename'])): ?>
                            <img src="<?= e(profile_image_url((string) $p['avatar_filename'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span aria-hidden="true"><?= e($initial) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="person-name"><?= e((string) $p['display_name']) ?></span>
                    <span class="person-go" aria-hidden="true">→</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
