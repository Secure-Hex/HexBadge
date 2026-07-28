<?php
/**
 * @var int                              $issuedThisMonth
 * @var int                              $issuedLastMonth
 * @var int                              $pending
 * @var array<int,array<string,mixed>>   $topTemplates
 * @var array<int,array<string,mixed>>   $recent
 */
?>
<h1>Dashboard</h1>

<div class="cards">
    <div class="card">
        <span class="card-value"><?= e((string) $issuedThisMonth) ?></span>
        <span class="card-label">Badges en lo que va del mes</span>
        <span class="card-delta"><?= e((string) $issuedLastMonth) ?> en el mes anterior completo</span>
    </div>
    <a class="card" href="/admin/badges?status=pending">
        <span class="card-value"><?= e((string) $pending) ?></span>
        <span class="card-label">Pendientes de aceptación</span>
        <span class="card-delta">Ver la lista →</span>
    </a>
</div>

<section>
    <h2>Templates más usados</h2>
    <?php if (empty($topTemplates)): ?>
        <p class="muted">Aún no hay templates con badges emitidos.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Template</th><th>Emitidos</th></tr></thead>
            <tbody>
            <?php foreach ($topTemplates as $t): ?>
                <tr>
                    <td><?= e((string) $t['name']) ?></td>
                    <td><?= e((string) $t['badges_issued']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section>
    <h2>Últimas emisiones</h2>
    <?php if (empty($recent)): ?>
        <p class="muted">Todavía no se han emitido badges.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Receptor</th><th>Badge</th><th>Fecha</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= e((string) $r['earner_name']) ?></td>
                    <td><?= e((string) $r['template_name']) ?></td>
                    <td><?= e(date_long((string) $r['issued_at'])) ?></td>
                    <td><span class="badge-status status-<?= e((string) $r['status']) ?>"><?= e(status_label((string) $r['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
