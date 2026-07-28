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
            Arrastrá cualquier texto o imagen para moverlo; la esquina de una imagen cambia su tamaño.
            Con la imagen seleccionada, las flechas la mueven y <kbd>Supr</kbd> la quita.
        </p>

        <form method="POST" action="/admin/templates/<?= e($uuid) ?>/designer" class="bde-save">
            <?= CSRF::field() ?>
            <button type="button" class="btn btn-sm" id="bde-reset-text" hidden>Devolver el texto a su lugar</button>
            <input type="hidden" name="recipe" id="bde-recipe" value="<?= e(json_encode($recipe, JSON_UNESCAPED_UNICODE)) ?>">
            <?php /* El PNG que rasteriza el navegador, para el correo y las redes. */ ?>
            <input type="hidden" name="raster" id="bde-raster" value="">
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

            <label for="f-metal">Metal</label>
            <select id="f-metal" data-k="metal">
                <option value="none">Sin metal (color propio)</option>
                <option value="gold">Oro</option>
                <option value="silver">Plata</option>
                <option value="bronze">Bronce</option>
                <option value="steel">Acero</option>
            </select>

            <label for="f-shapeRot">Giro de la figura <output id="o-shapeRot"></output></label>
            <input type="range" id="f-shapeRot" min="-180" max="180" step="1" data-k="shapeRot" data-unit="°">

            <label for="f-plate">Figura de fondo</label>
            <select id="f-plate" data-k="plate">
                <?php foreach (S::PLATES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <label for="f-plateScale">Cuánto asoma <output id="o-plateScale"></output></label>
            <input type="range" id="f-plateScale" min="100" max="145" step="1" data-k="plateScale" data-unit="%">

            <label for="f-ornament">Ornamento</label>
            <select id="f-ornament" data-k="ornament">
                <?php foreach (S::ORNAMENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <label for="f-ornScale">Tamaño del ornamento <output id="o-ornScale"></output></label>
            <input type="range" id="f-ornScale" min="40" max="160" step="1" data-k="ornScale" data-unit="%">
            <label for="f-ornY">Alto del ornamento <output id="o-ornY"></output></label>
            <input type="range" id="f-ornY" min="-25" max="25" step="1" data-k="ornY" data-unit="%">
            <label for="f-ringW">Grosor del borde <output id="o-ringW"></output></label>
            <input type="range" id="f-ringW" min="3" max="18" step="0.5" data-k="ringW" data-unit="%">
        </details>

        <details class="bde-group" open>
            <summary>Relieve y materia</summary>
            <p class="muted" style="font-size:.82rem">
                Lo que separa una figura de color plano de una pieza con espesor.
            </p>
            <label for="f-bevel">Biselado <output id="o-bevel"></output></label>
            <input type="range" id="f-bevel" min="0" max="100" step="1" data-k="bevel" data-unit="%">
            <label for="f-vignette">Sombra del borde <output id="o-vignette"></output></label>
            <input type="range" id="f-vignette" min="0" max="60" step="1" data-k="vignette" data-unit="%">
            <label for="f-grain">Grano de la superficie <output id="o-grain"></output></label>
            <input type="range" id="f-grain" min="0" max="40" step="1" data-k="grain" data-unit="%">
            <label for="f-glow">Resplandor <output id="o-glow"></output></label>
            <input type="range" id="f-glow" min="0" max="60" step="1" data-k="glow" data-unit="%">
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

        <details class="bde-group">
            <summary>Degradado y trama</summary>
            <label for="f-grad">Tipo de degradado</label>
            <select id="f-grad" data-k="grad">
                <?php foreach (S::GRADIENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>

            <label for="f-gradX">Centro horizontal <output id="o-gradX"></output></label>
            <input type="range" id="f-gradX" min="0" max="100" step="1" data-k="gradX" data-unit="%">
            <label for="f-gradY">Centro vertical <output id="o-gradY"></output></label>
            <input type="range" id="f-gradY" min="0" max="100" step="1" data-k="gradY" data-unit="%">
            <label for="f-gradSpread">Extensión <output id="o-gradSpread"></output></label>
            <input type="range" id="f-gradSpread" min="30" max="140" step="1" data-k="gradSpread" data-unit="%">
            <label for="f-gradAngle">Ángulo (lineal) <output id="o-gradAngle"></output></label>
            <input type="range" id="f-gradAngle" min="0" max="360" step="5" data-k="gradAngle" data-unit="°">

            <label for="f-pattern">Trama de fondo</label>
            <select id="f-pattern" data-k="pattern">
                <?php foreach (S::PATTERNS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <label for="f-patternOp">Intensidad de la trama <output id="o-patternOp"></output></label>
            <input type="range" id="f-patternOp" min="2" max="40" step="1" data-k="patternOp" data-unit="%">
        </details>

        <details class="bde-group" open>
            <summary>Textos</summary>
            <label for="f-mark">Iniciales <span class="muted">(hasta 3)</span></label>
            <input type="text" id="f-mark" maxlength="3" data-k="mark.value">
            <label for="f-markSize">Tamaño de las iniciales <output id="o-markSize"></output></label>
            <input type="range" id="f-markSize" min="0.5" max="2" step="0.05" data-k="markSize" data-unit="x">

            <label for="f-title">Título</label>
            <input type="text" id="f-title" maxlength="44" data-k="title">
            <label class="bd-check"><input type="checkbox" id="f-titleCaps" data-k="titleCaps"> Título en mayúsculas</label>

            <label for="f-level">Nivel</label>
            <input type="text" id="f-level" maxlength="24" data-k="level">
            <label class="bd-check"><input type="checkbox" id="f-ribbon" data-k="ribbon"> Mostrar el nivel en una cinta</label>
            <label class="bd-check"><input type="checkbox" id="f-textShadow" data-k="textShadow"> Sombra en los textos</label>

            <label for="f-stars">Estrellas de nivel <output id="o-stars"></output></label>
            <input type="range" id="f-stars" min="0" max="5" step="1" data-k="stars">

            <label for="f-ribbonStyle">Estilo de la cinta</label>
            <select id="f-ribbonStyle" data-k="ribbonStyle">
                <option value="tail">Con puntas</option>
                <option value="flat">Recta</option>
                <option value="folded">Plegada</option>
            </select>
            <label for="f-ribbonY">Alto de la cinta <output id="o-ribbonY"></output></label>
            <input type="range" id="f-ribbonY" min="30" max="95" step="0.5" data-k="ribbonY" data-unit="%">
            <label for="f-ribbonW">Ancho de la cinta <output id="o-ribbonW"></output></label>
            <input type="range" id="f-ribbonW" min="40" max="100" step="1" data-k="ribbonW" data-unit="%">
            <label class="bd-check"><input type="checkbox" id="f-ribbonAuto" data-k="ribbonAuto"> La cinta sigue al color del borde</label>
            <label for="f-ribbonColor">Color propio de la cinta</label>
            <input type="color" id="f-ribbonColor" data-k="ribbonColor">

            <label for="f-arcTop">Texto curvo arriba</label>
            <input type="text" id="f-arcTop" maxlength="34" data-k="arcTop">

            <label for="f-arcBottom">Texto curvo abajo</label>
            <input type="text" id="f-arcBottom" maxlength="34" data-k="arcBottom">
            <label for="f-arcR">Radio del texto curvo <output id="o-arcR"></output></label>
            <input type="range" id="f-arcR" min="26" max="44" step="0.5" data-k="arcR" data-unit="%">
            <label for="f-arcSize">Tamaño del texto curvo <output id="o-arcSize"></output></label>
            <input type="range" id="f-arcSize" min="2.6" max="7" step="0.1" data-k="arcSize" data-unit="%">
        </details>

        <details class="bde-group">
            <summary>Tipografía</summary>
            <label for="f-font">Familia</label>
            <select id="f-font" data-k="font">
                <?php foreach (S::FONTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v[1]) ?></option><?php endforeach; ?>
            </select>

            <label for="f-titleSize">Tamaño del título <output id="o-titleSize"></output></label>
            <input type="range" id="f-titleSize" min="0.6" max="1.6" step="0.05" data-k="titleSize" data-unit="x">

            <label for="f-tracking">Espaciado entre letras <output id="o-tracking"></output></label>
            <input type="range" id="f-tracking" min="0" max="12" step="0.5" data-k="tracking" data-unit="px">
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
                <label for="f-imgBlend">Fusión con el fondo</label>
                <select id="f-imgBlend">
                    <?php foreach (S::BLENDS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                </select>
                <label class="bd-check"><input type="checkbox" id="f-imgFlip"> Espejar</label>
                <label class="bd-check"><input type="checkbox" id="f-imgGray"> Sin color</label>
                <div class="bd-row" style="margin-top:.8rem">
                    <button type="button" class="btn btn-sm" id="bde-back">Enviar atrás</button>
                    <button type="button" class="btn btn-sm" id="bde-front">Traer al frente</button>
                </div>
                <button type="button" class="btn btn-sm" id="bde-remove" style="margin-top:.6rem">Quitar del diseño</button>
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
