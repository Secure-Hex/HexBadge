<?php
/**
 * Marcado de posiciones del certificado sobre la plantilla, con vista previa
 * en vivo: cada caja muestra texto de muestra con la tipografía/color/tamaño
 * elegidos, y el QR un placeholder.
 *
 * Reutilizable: lo usan tanto el certificado de una acreditación como una
 * plantilla de diploma guardada. Los destinos vienen por parámetro.
 *
 * @var array<int,array<string,mixed>> $fonts
 * @var string                         $config       JSON actual
 * @var string                         $imageUrl
 * @var string                         $saveUrl      POST del marcado
 * @var string                         $backUrl
 * @var string                         $backLabel
 * @var string                         $heading
 * @var string                         $subjectName  nombre para la muestra "curso"
 * @var string|null                    $deleteUrl    si se pasa, muestra el botón de quitar
 * @var string                         $deleteLabel
 * @var string                         $deleteConfirm
 */
use HexBadge\Core\CSRF;
use HexBadge\Core\QrCode;

$deleteUrl = $deleteUrl ?? null;

$fontOptions = '';
$fontFaces   = '';
foreach ($fonts as $f) {
    $fontOptions .= '<option value="' . (int) $f['id'] . '">' . e((string) $f['name']) . '</option>';
    $fontFaces   .= "@font-face{font-family:'cf-font-" . (int) $f['id'] . "';src:url('/admin/fonts/" . (int) $f['id'] . "/file');font-display:swap}\n";
}

// Datos de muestra para la vista previa.
$ts    = time();
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$samples = [
    'name'    => 'John Doe',
    'course'  => (string) $subjectName,
    'cert_id' => uuid4(),
    'date'    => [
        'long_es'    => (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts),
        'short'      => date('d/m/Y', $ts),
        'short_dash' => date('d-m-Y', $ts),
        'iso'        => date('Y-m-d', $ts),
        'long_en'    => date('F j, Y', $ts),
    ],
];
$qrSvg = QrCode::svg(public_url('verify/ejemplo')) ?? '';

/** Filas de control por campo de texto. */
$textRow = static function (string $key, string $label, bool $optional, string $fontOptions): string {
    $opt = $optional
        ? '<label class="cf-toggle"><input type="checkbox" class="cf-enable" data-field="' . $key . '"> Incluir</label>'
        : '';
    $date = $key === 'date'
        ? '<label class="cf-fmt-label" title="Formato de fecha">Formato'
            . '<select class="cf-format" data-field="date">'
            . '<option value="long_es">26 de junio de 2026</option>'
            . '<option value="short">26/06/2026</option>'
            . '<option value="short_dash">26-06-2026</option>'
            . '<option value="iso">2026-06-26</option>'
            . '<option value="long_en">June 26, 2026</option>'
            . '</select></label>'
        : '';
    return '<div class="cf-row" data-field="' . $key . '">'
        . '<div class="cf-row-head"><strong>' . e($label) . '</strong>' . $opt . '</div>'
        . '<div class="cf-controls">'
        . '<select class="cf-font" data-field="' . $key . '" title="Tipografía">' . $fontOptions . '</select>'
        . '<select class="cf-align" data-field="' . $key . '" title="Alineación"><option value="center">Centro</option><option value="left">Izq.</option><option value="right">Der.</option></select>'
        . '<input type="color" class="cf-color" data-field="' . $key . '" value="#1a2233" title="Color">'
        . $date
        . '</div></div>';
};
?>
<style>
<?= $fontFaces ?>
.cf-wrap{display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start}
.cf-stage{position:relative;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#fff;user-select:none}
.cf-stage img{display:block;width:100%;height:auto;pointer-events:none}
.cf-box{position:absolute;border:1px dashed var(--primary);background:rgba(21,101,216,.05);cursor:move;box-sizing:border-box;display:flex;align-items:center}
.cf-box.qr{background:transparent}
.cf-box .cf-text{display:block;white-space:nowrap;line-height:1;width:100%;pointer-events:none;font-size:16px;overflow:hidden}
.cf-box.qr svg{width:100%;height:100%;display:block;pointer-events:none}
.cf-box .cf-label{position:absolute;top:-17px;left:-1px;font-size:11px;background:var(--primary);color:#fff;padding:0 5px;border-radius:4px;white-space:nowrap;pointer-events:none}
.cf-box .cf-handle{position:absolute;right:-6px;bottom:-6px;width:13px;height:13px;background:var(--primary);border:2px solid #fff;border-radius:50%;cursor:nwse-resize;z-index:2}
.cf-panel .cf-row{border:1px solid var(--border);border-radius:8px;padding:.6rem .7rem;margin-bottom:.6rem;background:var(--surface)}
.cf-row-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
.cf-controls{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
.cf-controls select{flex:1;min-width:90px;padding:.35rem}
.cf-controls .cf-color{width:38px;height:34px;padding:2px}
.cf-toggle{font-size:.8rem;color:var(--muted);display:flex;align-items:center;gap:.3rem;margin:0}
.cf-row.off{opacity:.45}
.cf-fmt-label{flex:1 0 100%;display:flex;align-items:center;gap:.4rem;font-size:.8rem;color:var(--muted);margin:0}
.cf-fmt-label select{flex:1}
</style>

<div class="page-head">
    <h1><?= e((string) $heading) ?></h1>
    <a class="btn" href="<?= e((string) $backUrl) ?>"><?= e((string) $backLabel) ?></a>
</div>
<p class="muted">Arrastrá cada caja sobre la plantilla y ajustá su tamaño con el punto de la esquina. El texto de muestra (John Doe, la fecha de hoy, un ID y un QR de ejemplo) se actualiza al vuelo con la tipografía, color y tamaño que elijas. El QR real apuntará a la URL de verificación de cada acreditación.</p>

<form method="POST" action="<?= e((string) $saveUrl) ?>"
      class="cf-wrap" id="cf-form"
      data-config='<?= e($config) ?>'
      data-samples='<?= e(json_encode($samples, JSON_UNESCAPED_UNICODE)) ?>'>
    <?= CSRF::field() ?>
    <input type="hidden" name="config" id="cf-json">

    <div class="cf-stage" id="cf-stage">
        <img src="<?= e($imageUrl) ?>" alt="Plantilla" id="cf-img">
    </div>

    <div class="cf-panel">
        <?= $textRow('name', 'Nombre de la persona', false, $fontOptions) ?>
        <?= $textRow('course', 'Nombre del curso (opcional)', true, $fontOptions) ?>
        <?= $textRow('date', 'Fecha de emisión', false, $fontOptions) ?>
        <?= $textRow('cert_id', 'ID del certificado', false, $fontOptions) ?>
        <div class="cf-row" data-field="qr">
            <div class="cf-row-head"><strong>Código QR</strong></div>
            <div class="muted" style="font-size:.8rem">Arrastrá/redimensioná la caja punteada (cuadrada).</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:.5rem">Guardar certificado</button>
    </div>
</form>

<div id="cf-qr-src" hidden><?= $qrSvg ?></div>

<?php if ($deleteUrl !== null): ?>
<form method="POST" action="<?= e((string) $deleteUrl) ?>"
      onsubmit="return confirm('<?= e((string) $deleteConfirm) ?>')" style="margin-top:1rem">
    <?= CSRF::field() ?>
    <button type="submit" class="btn btn-danger btn-sm"><?= e((string) $deleteLabel) ?></button>
</form>
<?php endif; ?>

<script src="/assets/js/cert-marker.js?v=2"></script>
