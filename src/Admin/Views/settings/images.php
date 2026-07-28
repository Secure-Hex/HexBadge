<?php
/**
 * Mantenimiento: normalizar las imágenes ya subidas.
 *
 * @var array{pending:int,done:int,bytes:int,items:array<int,array{name:string,dir:string,w:int,h:int,bytes:int}>} $report
 * @var array{processed:int,failed:int,remaining:int,before:int,after:int,log:array<int,string>}|null $result
 */
use HexBadge\Core\CSRF;

$kb = static fn (int $b): string => number_format($b / 1024, 0, ',', '.') . ' KB';
$mb = static fn (int $b): string => number_format($b / 1048576, 2, ',', '.') . ' MB';
?>
<h1>Optimizar imágenes</h1>
<p class="muted" style="max-width:70ch">
    Las imágenes subidas antes de julio de 2026 se guardaban en su tamaño original.
    Esta tarea las reduce al tamaño con el que se muestran y las convierte a WebP.
    Las plantillas de diploma no se tocan: se imprimen en PDF y necesitan su resolución.
</p>

<?php if ($result !== null): ?>
    <section>
        <h2>Resultado de esta pasada</h2>
        <div class="cards">
            <div class="card">
                <span class="card-value"><?= (int) $result['processed'] ?></span>
                <span class="card-label"><?= $result['processed'] === 1 ? 'imagen optimizada' : 'imágenes optimizadas' ?></span>
            </div>
            <div class="card">
                <span class="card-value"><?= $mb(max(0, $result['before'] - $result['after'])) ?></span>
                <span class="card-label">liberados</span>
            </div>
            <div class="card">
                <span class="card-value"><?= (int) $result['remaining'] ?></span>
                <span class="card-label">pendientes</span>
            </div>
        </div>
        <?php if (!empty($result['log'])): ?>
            <details>
                <summary class="muted" style="cursor:pointer;font-size:.88rem">Ver detalle</summary>
                <ul class="muted" style="font-size:.85rem;margin-top:.6rem">
                    <?php foreach ($result['log'] as $line): ?>
                        <li><?= e($line) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section>
    <h2>Estado actual</h2>
    <?php if ($report['pending'] === 0): ?>
        <div class="alert alert-success" role="status">
            No hay nada pendiente: las <?= (int) $report['done'] ?> imágenes ya están optimizadas.
        </div>
    <?php else: ?>
        <div class="alert alert-warn">
            <strong><?= (int) $report['pending'] ?></strong>
            <?= $report['pending'] === 1 ? 'imagen ocupa' : 'imágenes ocupan' ?>
            <strong><?= $mb($report['bytes']) ?></strong> y se pueden reducir.
            Ya optimizadas: <?= (int) $report['done'] ?>.
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Archivo</th><th>Carpeta</th><th>Tamaño actual</th><th>Peso</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($report['items'], 0, 25) as $it): ?>
                    <tr>
                        <td><code style="font-size:.78rem"><?= e($it['name']) ?></code></td>
                        <td class="muted"><?= e(rtrim(str_replace('uploads/', '', $it['dir']), '/')) ?></td>
                        <td><?= (int) $it['w'] ?>×<?= (int) $it['h'] ?></td>
                        <td><?= $kb($it['bytes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($report['items']) > 25): ?>
            <p class="muted" style="font-size:.85rem">y <?= count($report['items']) - 25 ?> más.</p>
        <?php endif; ?>

        <form method="POST" action="/admin/maintenance/images" style="margin-top:1.25rem"
              data-confirm="Se van a reescribir <?= (int) $report['pending'] ?> imágenes y actualizar sus referencias. Hacé una copia de la carpeta uploads/ antes de continuar. ¿Seguimos?">
            <?= CSRF::field() ?>
            <button type="submit" class="btn btn-primary">Optimizar ahora</button>
            <small class="muted" style="display:block;margin-top:.5rem">
                Procesa por tandas de unos 20 segundos para no cortar la petición.
                Si quedan pendientes, volvé a ejecutar: repetir es seguro.
            </small>
        </form>
    <?php endif; ?>
</section>
