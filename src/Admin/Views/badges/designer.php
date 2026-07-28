<?php
/**
 * Diseñador de insignias.
 *
 * @var array<string,mixed> $template
 * @var array<string,mixed> $recipe
 * @var array<int,array{dir:string,file:string,url:string}> $assets
 * @var int $issued
 */
use HexBadge\Core\CSRF;
use HexBadge\Services\BadgeSvgService as S;

$uuid = (string) $template['uuid'];
?>
<div class="bde-head">
    <div>
        <h1>Diseñar insignia</h1>
        <p class="muted"><?= e((string) $template['name']) ?></p>
    </div>
    <a class="btn btn-ghost" href="/admin/templates/<?= e($uuid) ?>">Volver a la acreditación</a>
</div>

<?php if ($issued > 0): ?>
    <div class="alert alert-warn">
        Esta acreditación ya emitió <?= $issued ?> credencial<?= $issued === 1 ? '' : 'es' ?>.
        Al guardar, todas pasan a mostrar el diseño nuevo, incluidas las ya verificadas.
    </div>
<?php endif; ?>

<div class="bde">
    <!-- Lienzo -->
    <div class="bde-canvas-col">
        <div class="bde-stage" id="bde-stage">
            <div class="bde-svg" id="bde-svg"></div>
            <div class="bde-handles" id="bde-handles"></div>
        </div>
        <p class="muted bde-hint">
            Arrastrá una imagen para moverla y usá su esquina para cambiar el tamaño.
            Con la imagen seleccionada, las flechas la mueven y <kbd>Supr</kbd> la quita.
        </p>

        <form method="POST" action="/admin/templates/<?= e($uuid) ?>/designer" class="bde-save">
            <?= CSRF::field() ?>
            <input type="hidden" name="recipe" id="bde-recipe" value="<?= e(json_encode($recipe, JSON_UNESCAPED_UNICODE)) ?>">
            <button type="submit" class="btn btn-primary"
                    <?= $issued > 0 ? 'data-confirm="Se van a actualizar las ' . $issued . ' credenciales ya emitidas con este diseño. ¿Confirmás?"' : '' ?>>
                Guardar diseño
            </button>
        </form>
    </div>

    <!-- Controles -->
    <div class="bde-panel">
        <details class="bde-group" open>
            <summary>Figura</summary>
            <label for="f-shape">Forma</label>
            <select id="f-shape" data-k="shape">
                <?php foreach (S::SHAPES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>

            <div class="bd-row">
                <span><label for="f-finish">Acabado</label>
                    <select id="f-finish" data-k="finish">
                        <?php foreach (S::FINISHES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </span>
                <span><label for="f-ring">Borde</label>
                    <select id="f-ring" data-k="ring">
                        <?php foreach (S::RINGS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </span>
            </div>

            <label for="f-ornament">Ornamento</label>
            <select id="f-ornament" data-k="ornament">
                <?php foreach (S::ORNAMENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
        </details>

        <details class="bde-group" open>
            <summary>Colores</summary>
            <div class="bd-row">
                <span><label for="f-fill">Principal</label><input type="color" id="f-fill" data-k="fill"></span>
                <span><label for="f-fill2">Fondo del degradado</label><input type="color" id="f-fill2" data-k="fill2"></span>
            </div>
            <div class="bd-row">
                <span><label for="f-accent">Borde</label><input type="color" id="f-accent" data-k="accent"></span>
                <span><label for="f-ink">Texto</label><input type="color" id="f-ink" data-k="ink"></span>
            </div>
        </details>

        <details class="bde-group" open>
            <summary>Textos</summary>
            <label for="f-mark">Iniciales <span class="muted">(hasta 3)</span></label>
            <input type="text" id="f-mark" maxlength="3" data-k="mark.value">

            <label for="f-title">Título</label>
            <input type="text" id="f-title" maxlength="44" data-k="title">

            <label for="f-level">Nivel</label>
            <input type="text" id="f-level" maxlength="24" data-k="level">
            <label class="bd-check"><input type="checkbox" id="f-ribbon" data-k="ribbon"> Mostrar el nivel en una cinta</label>

            <label for="f-arcTop">Texto curvo arriba</label>
            <input type="text" id="f-arcTop" maxlength="34" data-k="arcTop">

            <label for="f-arcBottom">Texto curvo abajo</label>
            <input type="text" id="f-arcBottom" maxlength="34" data-k="arcBottom">
        </details>

        <details class="bde-group">
            <summary>Tipografía</summary>
            <label for="f-font">Familia</label>
            <select id="f-font" data-k="font">
                <?php foreach (S::FONTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v[1]) ?></option><?php endforeach; ?>
            </select>

            <label for="f-titleSize">Tamaño del título <output id="o-titleSize"></output></label>
            <input type="range" id="f-titleSize" min="0.6" max="1.6" step="0.05" data-k="titleSize">

            <label for="f-tracking">Espaciado entre letras <output id="o-tracking"></output></label>
            <input type="range" id="f-tracking" min="0" max="12" step="0.5" data-k="tracking">
        </details>

        <details class="bde-group" open>
            <summary>Imágenes <span class="muted" id="bde-count"></span></summary>
            <p class="muted" style="font-size:.82rem">Hasta <?= S::MAX_IMAGES ?>. Tocá una para agregarla al diseño.</p>
            <div class="bde-assets">
                <?php foreach ($assets as $a): ?>
                    <button type="button" class="bde-asset" data-dir="<?= e($a['dir']) ?>" data-file="<?= e($a['file']) ?>"
                            title="<?= e($a['file']) ?>">
                        <img src="<?= e($a['url']) ?>" alt="" loading="lazy">
                    </button>
                <?php endforeach; ?>
                <?php if ($assets === []): ?>
                    <p class="muted" style="font-size:.85rem">Todavía no hay imágenes. Subí una acá abajo.</p>
                <?php endif; ?>
            </div>

            <div class="bde-selected" id="bde-selected" hidden>
                <label for="f-imgW">Tamaño <output id="o-imgW"></output></label>
                <input type="range" id="f-imgW" min="4" max="90" step="1">
                <label for="f-imgRot">Rotación <output id="o-imgRot"></output></label>
                <input type="range" id="f-imgRot" min="-180" max="180" step="1">
                <label for="f-imgOp">Opacidad <output id="o-imgOp"></output></label>
                <input type="range" id="f-imgOp" min="5" max="100" step="1">
                <button type="button" class="btn btn-sm" id="bde-remove">Quitar del diseño</button>
            </div>
        </details>

        <details class="bde-group">
            <summary>Subir una imagen</summary>
            <form method="POST" action="/admin/templates/<?= e($uuid) ?>/designer/asset" enctype="multipart/form-data">
                <?= CSRF::field() ?>
                <input type="file" name="asset" accept="image/png,image/jpeg,image/svg+xml" required>
                <small class="muted">PNG, JPG o SVG, máximo 2MB. Queda disponible para todas tus insignias.</small>
                <button type="submit" class="btn btn-sm" style="margin-top:.6rem">Subir</button>
            </form>
        </details>
    </div>
</div>

<script src="<?= asset('js/badge-editor.js') ?>" defer></script>
