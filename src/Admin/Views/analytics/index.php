<?php
/**
 * @var array<int,array<string,mixed>> $byMonth
 * @var array<int,array<string,mixed>> $acceptance
 * @var array<int,array<string,mixed>> $topEarners
 */
$maxMonth = 0;
foreach ($byMonth as $m) { $maxMonth = max($maxMonth, (int) $m['total']); }
?>
<div style="display:flex;justify-content:space-between;align-items:center">
    <h1>Analytics</h1>
    <a class="btn" href="/admin/analytics/export">Exportar CSV</a>
</div>

<section>
    <h2>Badges por mes (últimos 12)</h2>
    <?php if (empty($byMonth)): ?>
        <p class="muted">Sin datos todavía.</p>
    <?php else: ?>
        <div style="display:flex;gap:.5rem;align-items:flex-end;height:180px;padding:1rem 0">
            <?php foreach ($byMonth as $m): $h = $maxMonth > 0 ? (int) round((int) $m['total'] / $maxMonth * 150) : 0; ?>
                <div style="flex:1;text-align:center">
                    <div style="background:var(--primary);height:<?= $h ?>px;border-radius:4px 4px 0 0" title="<?= e((string) $m['total']) ?>"></div>
                    <small class="muted" style="font-size:.7rem"><?= e(substr((string) $m['month'], 2)) ?></small>
                    <div style="font-size:.75rem"><?= e((string) $m['total']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section>
    <h2>Tasa de aceptación por template</h2>
    <table class="table">
        <thead><tr><th>Template</th><th>Emitidos</th><th>Aceptados</th><th>Tasa</th></tr></thead>
        <tbody>
        <?php foreach ($acceptance as $a): $rate = (int) $a['issued'] > 0 ? round((int) $a['accepted'] / (int) $a['issued'] * 100) : 0; ?>
            <tr><td><?= e((string) $a['name']) ?></td><td><?= e((string) $a['issued']) ?></td><td><?= e((string) $a['accepted']) ?></td><td><?= $rate ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section>
    <h2>Top receptores</h2>
    <table class="table">
        <thead><tr><th>Receptor</th><th>Email</th><th>Badges</th></tr></thead>
        <tbody>
        <?php foreach ($topEarners as $t): ?>
            <tr><td><?= e((string) $t['display_name']) ?></td><td class="muted"><?= e((string) $t['email']) ?></td><td><?= e((string) $t['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
