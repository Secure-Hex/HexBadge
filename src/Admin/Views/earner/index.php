<?php
/**
 * @var array<int,array<string,mixed>> $earners
 * @var string                         $search
 * @var int                            $page
 * @var int                            $totalPages
 * @var int                            $total
 * @var int                            $perPage
 * @var array<int,array<string,mixed>> $companies
 * @var int|null                       $companyFilter
 */
use HexBadge\Core\View;
$showCompany = count($companies) > 1;
?>
<h1>Receptores</h1>

<form method="GET" action="/admin/earners" style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;max-width:800px;margin-bottom:1rem">
    <div style="flex:1;min-width:200px">
        <label for="q">Buscar por nombre o email</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Ej: ana@correo.com o Pérez">
    </div>
    <?= View::renderPartial('layout/company_filter', ['companies' => $companies, 'selected' => $companyFilter]) ?>
    <?= View::renderPartial('layout/per_page_select', ['perPage' => $perPage]) ?>
    <button type="submit" class="btn">Buscar</button>
    <?php if ($search !== '' || ($showCompany && $companyFilter !== null)): ?>
        <a class="btn btn-sm" href="<?= e('/admin/earners' . ((int) $perPage !== 25 ? '?per=' . (int) $perPage : '')) ?>">Quitar filtros</a>
    <?php endif; ?>
</form>

<?php if (empty($earners)): ?>
    <p class="muted"><?= $search !== '' ? 'No hay receptores que coincidan con la búsqueda.' : 'Aún no hay receptores.' ?></p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Nombre</th><th>Email</th><th>Badges</th><th>Verificado</th></tr></thead>
        <tbody>
        <?php foreach ($earners as $e): ?>
            <tr>
                <td><a href="/admin/earners/<?= e((string) $e['uuid']) ?>"><?= e((string) $e['display_name']) ?></a></td>
                <td class="muted"><?= e((string) $e['email']) ?></td>
                <td><?= e((string) $e['badge_count']) ?></td>
                <td><?= ((int) $e['is_verified'] === 1) ? 'Sí' : 'No' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= View::renderPartial('layout/pagination', [
        'baseUrl'    => '/admin/earners',
        'page'       => $page,
        'totalPages' => $totalPages,
        'total'      => $total,
        'params'     => ['q' => $search, 'company' => $showCompany ? ($companyFilter ?? '') : '', 'per' => $perPage],
    ]) ?>
<?php endif; ?>
