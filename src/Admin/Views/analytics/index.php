<?php
/**
 * @var array<int,array<string,mixed>> $byMonth
 * @var array<int,array<string,mixed>> $acceptance
 * @var array<int,array<string,mixed>> $topEarners
 */

// El título promete 12 meses: se rellenan los que no tuvieron emisiones, así
// el gráfico muestra el período completo y las barras conservan su escala.
$totals = [];
foreach ($byMonth as $m) {
    $totals[(string) $m['month']] = (int) $m['total'];
}
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i month"));
    $months[] = ['key' => $key, 'total' => $totals[$key] ?? 0];
}
$maxMonth = max(1, max(array_column($months, 'total')));
$hasData  = array_sum(array_column($months, 'total')) > 0;
$shortM   = ['01' => 'ene', '02' => 'feb', '03' => 'mar', '04' => 'abr', '05' => 'may', '06' => 'jun',
             '07' => 'jul', '08' => 'ago', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dic'];
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <h1>Analytics</h1>
    <a class="btn" href="/admin/analytics/export">Exportar CSV</a>
</div>

<section>
    <h2>Badges por mes (últimos 12)</h2>
    <?php if (!$hasData): ?>
        <p class="muted">Todavía no hay emisiones en los últimos 12 meses.</p>
    <?php else: ?>
        <div class="chart-months">
            <?php foreach ($months as $m): ?>
                <?php
                $pct   = (int) round($m['total'] / $maxMonth * 100);
                [$y, $mm] = explode('-', $m['key']);
                $label = $shortM[$mm] . ' ' . substr($y, 2);
                ?>
                <div class="chart-col">
                    <div class="chart-track">
                        <span class="chart-value"><?= $m['total'] ?></span>
                        <div class="chart-bar" style="height:<?= max($m['total'] > 0 ? 3 : 0, $pct) ?>%"
                             role="img" aria-label="<?= e($label) ?>: <?= $m['total'] ?>"></div>
                    </div>
                    <small class="chart-label"><?= e($label) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section>
    <h2>Tasa de aceptación por template</h2>
    <?php if (empty($acceptance)): ?>
        <p class="muted">Sin templates con badges emitidos todavía.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Template</th><th>Emitidos</th><th>Aceptados</th><th>Tasa</th></tr></thead>
            <tbody>
            <?php foreach ($acceptance as $a): ?>
                <?php
                $issued = (int) $a['issued'];
                $rate   = $issued > 0 ? round((int) $a['accepted'] / $issued * 100) : 0;
                // Con muy pocas emisiones el porcentaje no dice nada: un 100%
                // sobre 1 emisión no es comparable con un 78% sobre 200.
                $thin = $issued < 5;
                ?>
                <tr>
                    <td><?= e((string) $a['name']) ?></td>
                    <td><?= $issued ?></td>
                    <td><?= e((string) $a['accepted']) ?></td>
                    <td<?= $thin ? ' class="muted"' : '' ?>>
                        <?= $rate ?>%<?= $thin ? ' <small>(pocos datos)</small>' : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section>
    <h2>Top receptores</h2>
    <?php if (empty($topEarners)): ?>
        <p class="muted">Sin receptores con badges todavía.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Receptor</th><th>Email</th><th>Badges</th></tr></thead>
            <tbody>
            <?php foreach ($topEarners as $t): ?>
                <tr><td><?= e((string) $t['display_name']) ?></td><td class="muted"><?= e((string) $t['email']) ?></td><td><?= e((string) $t['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
