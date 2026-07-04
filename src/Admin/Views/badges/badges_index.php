<?php
/**
 * @var array<int,array<string,mixed>> $badges
 * @var array<int,array<string,mixed>> $templates
 * @var array<string,mixed>            $filters
 * @var int                            $page
 * @var int                            $totalPages
 * @var int                            $total
 * @var int                            $perPage
 * @var array<int,array<string,mixed>> $companies
 * @var int|null                       $companyFilter
 * @var string                         $sort
 * @var string                         $dir
 */
use HexBadge\Core\View;
$showCompany = count($companies) > 1;

// Enlace de cabecera ordenable: recarga con sort/dir (el filtrado en vivo
// conserva el orden vía los hidden del formulario). Alterna asc/desc al reclicar.
$baseParams = [
    'q'        => $filters['q'] ?? '',
    'status'   => $filters['status'] ?? '',
    'template' => $filters['template_id'] ?? '',
    'company'  => $showCompany ? ($companyFilter ?? '') : '',
    'per'      => $perPage,
];
$sortLink = function (string $key, string $label) use ($baseParams, $sort, $dir): string {
    $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $qs = http_build_query(array_filter(
        $baseParams + ['sort' => $key, 'dir' => $nextDir],
        static fn ($v): bool => $v !== '' && $v !== null
    ));
    return '<a href="/admin/badges?' . e($qs) . '">' . e($label) . $arrow . '</a>';
};
?>
<h1>Badges emitidos</h1>

<form method="GET" action="/admin/badges" data-live style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;max-width:1000px">
    <div style="flex:2;min-width:200px">
        <label for="q">Buscar (receptor, email o badge)</label>
        <input type="search" id="q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Ej: ana@correo.com o Ciberseguridad">
    </div>
    <div style="flex:1">
        <label for="status">Estado</label>
        <select id="status" name="status">
            <option value="">Todos</option>
            <?php foreach (['pending' => 'Pendiente', 'accepted' => 'Aceptado', 'revoked' => 'Revocado', 'rejected' => 'Rechazado'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= (($filters['status'] ?? '') === $k) ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex:1">
        <label for="template">Template</label>
        <select id="template" name="template">
            <option value="">Todos</option>
            <?php foreach ($templates as $t): ?>
                <option value="<?= e((string) $t['id']) ?>" <?= (($filters['template_id'] ?? '') === (string) $t['id']) ? 'selected' : '' ?>><?= e((string) $t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?= View::renderPartial('layout/company_filter', ['companies' => $companies, 'selected' => $companyFilter]) ?>
    <?= View::renderPartial('layout/per_page_select', ['perPage' => $perPage]) ?>
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <button type="submit" class="btn">Filtrar</button>
    <?php
    $hasFilters = ($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== '' || ($filters['template_id'] ?? '') !== '' || ($showCompany && $companyFilter !== null);
    if ($hasFilters):
        $clearUrl = '/admin/badges' . ((int) $perPage !== 25 ? '?per=' . (int) $perPage : '');
    ?>
        <a class="btn btn-sm" href="<?= e($clearUrl) ?>">Quitar filtros</a>
    <?php endif; ?>
</form>

<div data-live-results>
<?php if (empty($badges)): ?>
    <p class="muted" style="margin-top:1.5rem">No hay badges para esos filtros.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th><?= $sortLink('receptor', 'Receptor') ?></th><th><?= $sortLink('email', 'Email') ?></th><th><?= $sortLink('badge', 'Badge') ?></th><?php if ($showCompany): ?><th><?= $sortLink('empresa', 'Empresa') ?></th><?php endif; ?><th><?= $sortLink('via', 'Vía') ?></th><th><?= $sortLink('emitido', 'Emitido') ?></th><th><?= $sortLink('aceptado', 'Aceptado') ?></th><th><?= $sortLink('estado', 'Estado') ?></th></tr></thead>
        <tbody>
        <?php foreach ($badges as $b): ?>
            <tr>
                <td><a href="/admin/badges/<?= e((string) $b['uuid']) ?>"><?= e((string) $b['earner_name']) ?></a></td>
                <td class="muted"><?= e((string) $b['earner_email']) ?></td>
                <td><?= e((string) $b['template_name']) ?></td>
                <?php if ($showCompany): ?><td class="muted"><?= e((string) ($b['company_name'] ?? '—')) ?></td><?php endif; ?>
                <td><?= e((string) $b['issued_via']) ?></td>
                <td><?= e((string) $b['issued_at']) ?></td>
                <td class="muted"><?= $b['accepted_at'] !== null ? e((string) $b['accepted_at']) : '—' ?></td>
                <td><span class="badge-status status-<?= e((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= View::renderPartial('layout/pagination', [
        'baseUrl'    => '/admin/badges',
        'page'       => $page,
        'totalPages' => $totalPages,
        'total'      => $total,
        'params'     => [
            'q'        => $filters['q'] ?? '',
            'status'   => $filters['status'] ?? '',
            'template' => $filters['template_id'] ?? '',
            'company'  => $showCompany ? ($companyFilter ?? '') : '',
            'per'      => $perPage,
            'sort'     => $sort,
            'dir'      => $dir,
        ],
    ]) ?>
<?php endif; ?>
</div>
