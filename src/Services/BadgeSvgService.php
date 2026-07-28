<?php

declare(strict_types=1);

namespace HexBadge\Services;

/**
 * Genera la insignia como SVG.
 *
 * Reemplaza al dibujo con GD porque el techo de calidad estaba en la
 * herramienta, no en las opciones: GD no tiene degradados —había que emularlos
 * línea por línea—, ni texto sobre una curva, ni sombras internas, ni forma de
 * componer ornamentos vectoriales. En SVG todo eso es nativo, pesa unos pocos
 * KB y escala sin perder nitidez.
 *
 * El SVG es la fuente de verdad. Para el correo y las vistas previas de redes
 * sociales hace falta además un PNG, porque Gmail y Outlook no renderizan SVG:
 * de eso se encarga BadgeRasterService, partiendo de este mismo SVG para que no
 * existan dos dibujos distintos de la misma receta.
 */
final class BadgeSvgService
{
    /** Lienzo interno. Todo se expresa en estas unidades y luego escala solo. */
    public const VIEWBOX = 512;

    /** Sufijo de los ids internos del SVG en curso. */
    private string $uid = 'b';

    /** @var array<string,string> */
    public const SHAPES = [
        'circle'  => 'Círculo',
        'hexagon' => 'Hexágono',
        'octagon' => 'Octágono',
        'shield'  => 'Escudo',
        'rounded' => 'Cuadrado redondeado',
        'diamond' => 'Rombo',
        'rosette' => 'Roseta',
        'seal'    => 'Sello dentado',
        'star'    => 'Estrella',
    ];

    /**
     * Figura que asoma por detrás del cuerpo.
     *
     * Es el recurso con el que están hechas casi todas las medallas reales: una
     * pieza sobre otra, girada, para que el borde se lea como relieve y no como
     * un contorno dibujado.
     *
     * @var array<string,string>
     */
    public const PLATES = [
        'none'    => 'Ninguna',
        'star'    => 'Estrella',
        'rosette' => 'Roseta',
        'seal'    => 'Sello dentado',
        'same'    => 'La misma figura',
    ];

    /** Modos de fusión de una capa de imagen sobre el cuerpo. */
    public const BLENDS = [
        'normal'     => 'Normal',
        'multiply'   => 'Multiplicar',
        'screen'     => 'Trama',
        'overlay'    => 'Superponer',
        'soft-light' => 'Luz suave',
        'luminosity' => 'Luminosidad',
    ];

    /** @var array<string,string> */
    public const FINISHES = [
        'satin'  => 'Satinado',
        'gloss'  => 'Brillante',
        'metal'  => 'Metal pulido',
        'matte'  => 'Mate',
        'flat'   => 'Plano',
    ];

    /**
     * Paletas metálicas.
     *
     * El oro no es amarillo con un degradado: son ocres, blancos cálidos y
     * sombras marrones alternados, que es lo que el ojo lee como metal.
     *
     * @var array<string,array{0:string,1:string,2:string,3:string}>
     */
    public const METALS = [
        'none'   => ['', '', '', ''],
        'gold'   => ['#f7e7a8', '#c9a227', '#8a6a12', '#fff6d6'],
        'silver' => ['#f2f5f8', '#aeb7c2', '#6b7480', '#ffffff'],
        'bronze' => ['#eec9a3', '#a9713c', '#6b4220', '#ffe9cf'],
        'steel'  => ['#dbe4ee', '#7d8b9e', '#414c5b', '#f4f8fc'],
    ];

    /** Cómo se orienta el degradado del cuerpo. */
    public const GRADIENTS = [
        'radial' => 'Radial',
        'linear' => 'Lineal',
        'conic'  => 'Barrido',
    ];

    /** Tramas de fondo. */
    public const PATTERNS = [
        'none'    => 'Ninguna',
        'guilloche' => 'Guilloché',
        'hex'     => 'Panal',
        'dots'    => 'Puntos',
        'diagonal'=> 'Diagonales',
        'sunburst'=> 'Sol naciente',
    ];

    /** @var array<string,string> */
    public const RINGS = [
        'double' => 'Doble',
        'beaded' => 'Con puntos',
        'notched'=> 'Dentado',
        'single' => 'Simple',
        'none'   => 'Sin borde',
    ];

    /** @var array<string,string> Ornamentos alrededor del centro. */
    public const ORNAMENTS = [
        'none'    => 'Ninguno',
        'laurel'  => 'Laureles',
        'stars'   => 'Estrellas',
        'rays'    => 'Rayos',
        'chevron' => 'Galones',
    ];

    /** @var array<string,array{0:string,1:string}> familia => [css, nombre visible] */
    public const FONTS = [
        'sans'  => ["'Public Sans', system-ui, sans-serif", 'Sans (Public Sans)'],
        'serif' => ["'Playfair Display', Georgia, serif", 'Serif (Playfair)'],
        'mono'  => ["ui-monospace, 'SF Mono', Menlo, monospace", 'Monoespaciada'],
    ];

    /**
     * Normaliza la receta. Nunca lanza: vista previa y guardado tienen que ser
     * infalibles, así que todo valor inválido cae a uno por defecto.
     *
     * @param  array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitize(array $in): array
    {
        $hex = static function (mixed $v, string $def): string {
            $v = ltrim((string) $v, '#');
            return preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) === 1 ? '#' . strtolower($v) : $def;
        };
        $txt = static function (mixed $v, int $max): string {
            $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
            if (mb_strlen($t) <= $max) {
                return $t;
            }
            $cut = mb_substr($t, 0, $max);
            $sp  = mb_strrpos($cut, ' ');
            return rtrim($sp !== false ? mb_substr($cut, 0, $sp) : $cut);
        };
        $num = static fn (mixed $v, float $min, float $max, float $def): float
            => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;
        $pick = static fn (mixed $v, array $set, string $def): string
            => isset($set[(string) $v]) ? (string) $v : $def;

        $markIn = is_array($in['mark'] ?? null) ? $in['mark'] : [];
        $type   = in_array($markIn['type'] ?? '', ['none', 'initials'], true) ? (string) $markIn['type'] : 'none';
        $value  = '';
        if ($type === 'initials') {
            $raw   = preg_replace('/[^\p{L}\p{N}]/u', '', (string) ($markIn['value'] ?? '')) ?? '';
            $value = mb_strtoupper(mb_substr($raw, 0, 3));
            if ($value === '') {
                $type = 'none';
            }
        }

        return [
            'shape'    => $pick($in['shape'] ?? '', self::SHAPES, 'circle'),
            'shapeRot' => $num($in['shapeRot'] ?? null, -180, 180, 0),
            'plate'    => $pick($in['plate'] ?? '', self::PLATES, 'none'),
            'plateScale' => $num($in['plateScale'] ?? null, 100, 145, 118),
            // Relieve y materia. Son los cuatro controles que separan una figura
            // plana de una pieza: luz rasante en el canto, grano, sombra en el
            // borde interior y halo.
            'bevel'    => $num($in['bevel'] ?? null, 0, 100, 0),
            'grain'    => $num($in['grain'] ?? null, 0, 40, 0),
            'vignette' => $num($in['vignette'] ?? null, 0, 60, 0),
            'glow'     => $num($in['glow'] ?? null, 0, 60, 0),
            'fill'     => $hex($in['fill'] ?? '', '#1565d8'),
            'fill2'    => $hex($in['fill2'] ?? '', '#0b3b8c'),
            'accent'   => $hex($in['accent'] ?? '', '#0f1b2e'),
            'ink'      => $hex($in['ink'] ?? '', '#ffffff'),
            'finish'   => $pick($in['finish'] ?? '', self::FINISHES, 'satin'),
            'metal'    => $pick($in['metal'] ?? '', self::METALS, 'none'),
            'grad'     => $pick($in['grad'] ?? '', self::GRADIENTS, 'radial'),
            'gradX'    => $num($in['gradX'] ?? null, 0, 100, 50),
            'gradY'    => $num($in['gradY'] ?? null, 0, 100, 32),
            'gradAngle'=> $num($in['gradAngle'] ?? null, 0, 360, 160),
            'gradSpread' => $num($in['gradSpread'] ?? null, 30, 140, 78),
            'pattern'  => $pick($in['pattern'] ?? '', self::PATTERNS, 'none'),
            'patternOp'=> $num($in['patternOp'] ?? null, 2, 40, 12),
            'stars'    => (int) $num($in['stars'] ?? null, 0, 5, 0),
            'ribbonY'  => $num($in['ribbonY'] ?? null, 30, 95, 69.5),
            'ribbonW'  => $num($in['ribbonW'] ?? null, 40, 100, 80),
            'ribbonStyle' => in_array($in['ribbonStyle'] ?? '', ['tail', 'flat', 'folded'], true)
                ? (string) $in['ribbonStyle'] : 'tail',
            // Por defecto la cinta sigue al borde, que es lo que ya hacía; el
            // color propio es una excepción que hay que pedir.
            'ribbonAuto'  => !array_key_exists('ribbonAuto', $in) || !empty($in['ribbonAuto']),
            'ribbonColor' => $hex($in['ribbonColor'] ?? '', '#8a1c1c'),
            'ornY'     => $num($in['ornY'] ?? null, -25, 25, 0),
            'ornScale' => $num($in['ornScale'] ?? null, 40, 160, 100),
            'ringW'    => $num($in['ringW'] ?? null, 3, 18, 8.5),
            'arcR'     => $num($in['arcR'] ?? null, 26, 44, 37.2),
            'arcSize'  => $num($in['arcSize'] ?? null, 2.6, 7, 4.5),
            'textShadow' => !empty($in['textShadow']),
            'ring'     => $pick($in['ring'] ?? '', self::RINGS, 'double'),
            'ornament' => $pick($in['ornament'] ?? '', self::ORNAMENTS, 'laurel'),
            'font'     => $pick($in['font'] ?? '', self::FONTS, 'sans'),
            'mark'     => ['type' => $type, 'value' => $value],
            'markSize' => $num($in['markSize'] ?? null, 0.5, 2.0, 1.0),
            'titleCaps' => !empty($in['titleCaps']),
            'title'    => $txt($in['title'] ?? '', 44),
            'level'    => $txt($in['level'] ?? '', 24),
            'ribbon'   => !empty($in['ribbon']),
            // Texto curvado sobre el anillo: el recurso que más distingue una
            // insignia real de una figura con una palabra encima.
            'arcTop'    => $txt($in['arcTop'] ?? '', 34),
            'arcBottom' => $txt($in['arcBottom'] ?? '', 34),
            'titleSize' => $num($in['titleSize'] ?? null, 0.6, 1.6, 1.0),
            'tracking'  => $num($in['tracking'] ?? null, 0, 12, 0),
            'logo'      => !empty($in['logo']),
            'images'    => self::sanitizeImages($in['images'] ?? null),
            'pos'       => self::sanitizePos($in['pos'] ?? null),
        ];
    }


    /** Cuántas imágenes se pueden apilar sobre una insignia. */
    public const MAX_IMAGES = 6;

    /** Carpetas de las que se pueden tomar imágenes. La clave viaja en la receta. */
    public const IMAGE_DIRS = [
        'logo'  => 'uploads/logos/',
        'badge' => 'uploads/badges/',
    ];

    /**
     * Normaliza las capas de imagen.
     *
     * `src` nunca es una ruta: son una clave de carpeta permitida y un nombre de
     * archivo del que solo se conserva el basename. Una receta llega del cliente
     * y podría pedir cualquier cosa del disco.
     *
     * @return array<int,array{dir:string,file:string,x:float,y:float,w:float,rot:float,op:float}>
     */
    private static function sanitizeImages(mixed $in): array
    {
        if (!is_array($in)) {
            return [];
        }
        $num = static fn (mixed $v, float $min, float $max, float $def): float
            => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;

        $out = [];
        foreach ($in as $img) {
            if (!is_array($img) || count($out) >= self::MAX_IMAGES) {
                continue;
            }
            $dir  = isset(self::IMAGE_DIRS[$img['dir'] ?? '']) ? (string) $img['dir'] : 'logo';
            $file = basename((string) ($img['file'] ?? ''));
            if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
                continue;
            }
            $out[] = [
                'dir'  => $dir,
                'file' => $file,
                'x'    => $num($img['x'] ?? null, 0, 1, 0.5),
                'y'    => $num($img['y'] ?? null, 0, 1, 0.35),
                'w'    => $num($img['w'] ?? null, 0.04, 0.9, 0.20),
                'rot'  => $num($img['rot'] ?? null, -180, 180, 0),
                'op'   => $num($img['op'] ?? null, 0.05, 1, 1),
                'flip' => !empty($img['flip']),
                'gray' => !empty($img['gray']),
                'blend' => isset(self::BLENDS[$img['blend'] ?? '']) ? (string) $img['blend'] : 'normal',
            ];
        }

        return $out;
    }

    /**
     * Posiciones manuales de los bloques de texto.
     *
     * Solo aparecen los que se movieron a mano: el resto sigue el orden
     * automático, para que escribir un título no obligue a acomodarlo.
     *
     * @return array<string,array{x:float,y:float}>
     */
    private static function sanitizePos(mixed $in): array
    {
        if (!is_array($in)) {
            return [];
        }
        $out = [];
        foreach (['mark', 'title', 'level'] as $k) {
            if (!isset($in[$k]) || !is_array($in[$k])) {
                continue;
            }
            $x = $in[$k]['x'] ?? null;
            $y = $in[$k]['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y)) {
                continue;
            }
            $out[$k] = ['x' => max(0.0, min(1.0, (float) $x)), 'y' => max(0.0, min(1.0, (float) $y))];
        }

        return $out;
    }

    /**
     * Embebe las capas de imagen.
     *
     * Van como data URI y no como enlace porque un SVG mostrado dentro de un
     * <img> corre en modo aislado: el navegador no pide ningún recurso externo,
     * así que una referencia por URL sencillamente no se ve. Verificado.
     */
    private function imageLayers(array $r, float $vb): string
    {
        $out = '';
        foreach ($r['images'] as $img) {
            $path = BASE_PATH . '/apps/earner/public/' . self::IMAGE_DIRS[$img['dir']] . $img['file'];
            if (!is_file($path)) {
                continue;
            }
            $bytes = (string) @file_get_contents($path);
            if ($bytes === '' || strlen($bytes) > 400 * 1024) {
                continue;   // una imagen enorme haría inmanejable el SVG
            }
            // getimagesizefromstring() no entiende SVG: es vectorial, no un mapa
            // de bits. Sin este caso aparte, un SVG se embebía declarado como
            // PNG y el navegador no mostraba nada.
            if (str_ends_with(strtolower($img['file']), '.svg') || str_contains(substr($bytes, 0, 512), '<svg')) {
                $mime  = 'image/svg+xml';
                $ratio = self::svgRatio($bytes);
            } else {
                $info  = @getimagesizefromstring($bytes);
                if (!is_array($info)) {
                    continue;   // no es una imagen que sepamos embeber
                }
                $mime  = (string) $info['mime'];
                $ratio = $info[0] > 0 ? $info[1] / $info[0] : 1.0;
            }

            $w = $img['w'] * $vb;
            $h = $w * $ratio;
            $x = $img['x'] * $vb - $w / 2;
            $y = $img['y'] * $vb - $h / 2;

            $cxi = $x + $w / 2;
            $cyi = $y + $h / 2;
            $tr  = [];
            if ($img['rot'] != 0.0) {
                $tr[] = 'rotate(' . round($img['rot'], 1) . ' ' . round($cxi, 1) . ' ' . round($cyi, 1) . ')';
            }
            if ($img['flip']) {
                $tr[] = 'translate(' . round($cxi * 2, 1) . ' 0) scale(-1 1)';
            }
            $transform = $tr === [] ? '' : ' transform="' . implode(' ', $tr) . '"';
            $filter    = $img['gray'] ? ' filter="url(#gray' . $this->uid . ')"' : '';
            // Fusionar en vez de apoyar: un logo en «multiplicar» o «luz suave»
            // toma el degradado del cuerpo y deja de parecer una calcomanía.
            $blend     = $img['blend'] !== 'normal' ? ' style="mix-blend-mode:' . $img['blend'] . '"' : '';

            $out .= '<image href="data:' . $mime . ';base64,' . base64_encode($bytes) . '"'
                . ' x="' . round($x, 1) . '" y="' . round($y, 1) . '"'
                . ' width="' . round($w, 1) . '" height="' . round($h, 1) . '"'
                . ($img['op'] < 1 ? ' opacity="' . round($img['op'], 2) . '"' : '')
                . $transform . $filter . $blend
                . ' preserveAspectRatio="xMidYMid meet"/>';
        }

        return $out;
    }

    /**
     * Devuelve el SVG de la receta.
     *
     * @param array<string,mixed> $recipe
     * @param ?string             $logoDataUri Logo ya embebido como data URI, si se pidió.
     */
    public function render(array $recipe, ?string $logoDataUri = null): string
    {
        $r  = self::sanitize($recipe);
        // Sufijo propio para cada referencia interna.
        $this->uid = 'b' . substr(hash('crc32b', serialize($r) . random_bytes(4)), 0, 6);
        $vb = self::VIEWBOX;
        $c  = $vb / 2;

        $defs  = $this->defs($r);
        $body  = '';
        $rad   = $vb * 0.455;
        $rot   = $this->rotAttr($r, $c);

        // Cuerpo y borde. La placa entra en el mismo grupo que la figura para
        // que la sombra sea una sola, de la pieza entera y no de cada parte.
        $bevel = $r['bevel'] > 0 ? ' filter="url(#bev' . $this->uid . ')"' : '';
        $body .= '<g filter="url(#drop' . $this->uid . ')">'
            . $this->plateElement($r, $c, $rad)
            . '<path d="' . $this->shapePath($r['shape'], $c, $rad) . '"' . $rot
            . ' fill="url(#bg' . $this->uid . ')"' . $bevel . '/>'
            . '</g>';
        $body .= $rot === '' ? $this->ringElement($r, $c, $rad)
            : '<g' . $rot . '>' . $this->ringElement($r, $c, $rad) . '</g>';
        if ($r['pattern'] !== 'none') {
            $fill = $r['pattern'] === 'sunburst'
                ? '<use href="#pat' . $this->uid . '"/>'
                : '<rect x="0" y="0" width="' . $vb . '" height="' . $vb . '" fill="url(#pat' . $this->uid . ')"/>';
            $body .= '<g clip-path="url(#clipShape' . $this->uid . ')" opacity="' . round($r['patternOp'] / 100, 2) . '">'
                . $fill . '</g>';
        }

        // El grano va sobre el color y debajo del reflejo: es materia del cuerpo,
        // no suciedad encima de la pieza terminada.
        if ($r['grain'] > 0) {
            $body .= '<rect x="0" y="0" width="' . $vb . '" height="' . $vb . '" filter="url(#grain' . $this->uid . ')"'
                . ' clip-path="url(#clipShape' . $this->uid . ')" opacity="' . round($r['grain'] / 100, 2)
                . '" style="mix-blend-mode:overlay"/>';
        }

        if ($r['finish'] === 'gloss' || $r['finish'] === 'satin') {
            // Reflejo superior: una elipse recortada a la forma, muy tenue.
            $body .= '<ellipse cx="' . $c . '" cy="' . round($vb * 0.30, 1) . '" rx="' . round($vb * 0.34, 1)
                . '" ry="' . round($vb * 0.20, 1) . '" fill="url(#gloss' . $this->uid . ')" clip-path="url(#clipShape' . $this->uid . ')"/>';
        }

        if ($r['vignette'] > 0) {
            $body .= '<rect x="0" y="0" width="' . $vb . '" height="' . $vb . '" fill="url(#vig' . $this->uid . ')"'
                . ' clip-path="url(#clipShape' . $this->uid . ')"/>';
        }

        $body .= $this->ornamentElement($r, $c, $vb);
        $body .= $this->arcText($r, $c, $vb);

        if ($logoDataUri !== null && $r['logo']) {
            $lw = $vb * 0.17;
            $body .= '<image href="' . e($logoDataUri) . '" x="' . round($c - $lw / 2, 1)
                . '" y="' . round($vb * 0.235 - $lw / 2, 1) . '" width="' . round($lw, 1)
                . '" height="' . round($lw, 1) . '" preserveAspectRatio="xMidYMid meet"/>';
        }

        $body .= $this->imageLayers($r, $vb);
        $layout = [];
        $body .= $this->centerText($r, $c, $vb, ($logoDataUri !== null && $r['logo']) || $r['images'] !== [], $layout);
        if ($r['stars'] > 0) {
            $body .= $this->levelStars($r, $c, $vb);
        }
        if ($r['ribbon'] && $r['level'] !== '') {
            $body .= $this->ribbon($r, $c, $vb);
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $vb . ' ' . $vb . '" '
            . 'width="' . $vb . '" height="' . $vb . '" role="img" '
            . 'data-layout="' . e(json_encode($layout)) . '">'
            . '<defs>' . $defs . '</defs>'
            . $body
            . '</svg>';
    }

    /** Degradados, tramas, sombra y recorte. */
    private function defs(array $r): string
    {
        $u  = $this->uid;
        $vb = self::VIEWBOX;

        // Paradas del cuerpo. Un metal no es un color con degradado: son claros,
        // medios y sombras alternados, que es lo que el ojo lee como reflejo.
        if ($r['metal'] !== 'none') {
            [$lite, $mid, $dark, $hot] = self::METALS[$r['metal']];
            $stops = [[0, $hot], [12, $lite], [30, $mid], [46, $dark], [58, $mid],
                      [72, $lite], [84, $mid], [100, $dark]];
        } elseif ($r['finish'] === 'flat') {
            $stops = [[0, $r['fill']], [100, $r['fill']]];
        } elseif ($r['finish'] === 'metal') {
            $f = $r['fill'];
            $stops = [[0, self::shade($f, 0.45)], [18, $f], [34, self::shade($f, -0.30)],
                      [50, self::shade($f, 0.22)], [66, self::shade($f, -0.34)],
                      [84, $f], [100, self::shade($f, -0.42)]];
        } elseif ($r['finish'] === 'matte') {
            $stops = [[0, self::shade($r['fill'], 0.06)], [100, self::shade($r['fill'], -0.10)]];
        } else {
            $stops = [[0, self::shade($r['fill'], 0.28)], [55, $r['fill']], [100, $r['fill2']]];
        }

        $body = '';
        foreach ($stops as [$off, $col]) {
            $body .= '<stop offset="' . $off . '%" stop-color="' . $col . '"/>';
        }

        // El degradado se puede orientar y desplazar: el punto de luz manda en
        // cómo se lee el volumen de la pieza.
        if ($r['grad'] === 'linear') {
            $a  = deg2rad($r['gradAngle']);
            $bg = '<linearGradient id="bg' . $u . '" x1="' . round(0.5 - cos($a) / 2, 3) . '" y1="' . round(0.5 - sin($a) / 2, 3)
                . '" x2="' . round(0.5 + cos($a) / 2, 3) . '" y2="' . round(0.5 + sin($a) / 2, 3) . '">' . $body . '</linearGradient>';
        } elseif ($r['grad'] === 'conic') {
            // SVG no tiene degradado cónico: se arma con cuñas, que además da un
            // reflejo de barrido más creíble que un degradado suave.
            $bg = '<radialGradient id="bg' . $u . '" cx="' . $r['gradX'] . '%" cy="' . $r['gradY']
                . '%" r="' . $r['gradSpread'] . '%">' . $body . '</radialGradient>';
        } else {
            $bg = '<radialGradient id="bg' . $u . '" cx="' . $r['gradX'] . '%" cy="' . $r['gradY']
                . '%" r="' . $r['gradSpread'] . '%">' . $body . '</radialGradient>';
        }

        $defs = $bg
            . '<linearGradient id="gloss' . $u . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%" stop-color="#ffffff" stop-opacity=".38"/>'
            . '<stop offset="100%" stop-color="#ffffff" stop-opacity="0"/></linearGradient>';

        // El aro sigue al metal cuando hay uno elegido.
        if ($r['metal'] !== 'none') {
            [$lite, $mid, $dark, $hot] = self::METALS[$r['metal']];
            $defs .= '<linearGradient id="ringGrad' . $u . '" x1="0" y1="0" x2="0.7" y2="1">'
                . '<stop offset="0%" stop-color="' . $hot . '"/><stop offset="22%" stop-color="' . $mid . '"/>'
                . '<stop offset="45%" stop-color="' . $dark . '"/><stop offset="62%" stop-color="' . $lite . '"/>'
                . '<stop offset="100%" stop-color="' . $mid . '"/></linearGradient>';
        } else {
            $defs .= '<linearGradient id="ringGrad' . $u . '" x1="0" y1="0" x2="0" y2="1">'
                . '<stop offset="0%" stop-color="' . self::shade($r['accent'], 0.45) . '"/>'
                . '<stop offset="50%" stop-color="' . $r['accent'] . '"/>'
                . '<stop offset="100%" stop-color="' . self::shade($r['accent'], -0.3) . '"/></linearGradient>';
        }

        // El halo entra en el mismo filtro que la sombra: en SVG un elemento
        // admite un solo `filter`, y encadenar los dos primitivos sale más
        // barato que envolver la pieza en otro grupo.
        $defs .= '<filter id="drop' . $u . '" x="-45%" y="-45%" width="190%" height="190%">'
            . ($r['glow'] > 0
                ? '<feDropShadow dx="0" dy="0" stdDeviation="' . round(4 + $r['glow'] * 0.34, 1)
                  . '" flood-color="' . self::shade($r['accent'], 0.35) . '" flood-opacity="'
                  . round(0.25 + $r['glow'] / 120, 2) . '"/>'
                : '')
            . '<feDropShadow dx="0" dy="6" stdDeviation="9" flood-color="#0f1b2e" flood-opacity=".34"/></filter>'
            . '<filter id="tsh' . $u . '" x="-20%" y="-20%" width="140%" height="140%">'
            . '<feDropShadow dx="0" dy="2" stdDeviation="2.4" flood-color="#000000" flood-opacity=".45"/></filter>'
            . '<filter id="gray' . $u . '"><feColorMatrix type="saturate" values="0"/></filter>'
            . '<clipPath id="clipShape' . $u . '"><path d="' . $this->shapePath($r['shape'], $vb / 2, $vb * 0.455) . '"'
            . $this->rotAttr($r, $vb / 2) . '/></clipPath>'
            . $this->bevelDef($r)
            . $this->materialDefs($r)
            . $this->patternDef($r);

        return $defs;
    }

    /**
     * Biselado: luz rasante sobre el canto de la figura.
     *
     * Es lo que convierte una silueta rellena en una pieza con espesor. Se hace
     * iluminando el alfa desenfocado —el borde queda como una pendiente— y
     * sumando ese brillo especular sobre el color original.
     */
    private function bevelDef(array $r): string
    {
        if ($r['bevel'] <= 0) {
            return '';
        }
        $s = $r['bevel'] / 100;

        // El exponente alto es lo que hace que esto sea un bisel y no un velo: en
        // una superficie plana la normal es constante, así que con exponente bajo
        // toda la cara devuelve el mismo reflejo y la pieza sale lavada. Con 64,
        // el plano aporta casi cero y solo el canto —donde el desenfoque del alfa
        // inclina la normal— se enciende.
        return '<filter id="bev' . $this->uid . '" x="-20%" y="-20%" width="140%" height="140%">'
            . '<feGaussianBlur in="SourceAlpha" stdDeviation="' . round(2 + 3.5 * $s, 1) . '" result="bblur"/>'
            . '<feSpecularLighting in="bblur" surfaceScale="' . round(3 + 12 * $s, 1)
            . '" specularConstant="' . round(0.7 + 0.9 * $s, 2)
            . '" specularExponent="64" lighting-color="#ffffff" result="bspec">'
            . '<feDistantLight azimuth="235" elevation="52"/></feSpecularLighting>'
            . '<feComposite in="bspec" in2="SourceAlpha" operator="in" result="bclip"/>'
            . '<feComposite in="SourceGraphic" in2="bclip" operator="arithmetic" k1="0" k2="1" k3="1" k4="0"/>'
            . '</filter>';
    }

    /** Grano de la superficie y sombra del borde interior. */
    private function materialDefs(array $r): string
    {
        $u   = $this->uid;
        $out = '';

        if ($r['grain'] > 0) {
            // Ruido fractal desaturado: el mismo recurso con el que se imita el
            // papel de un diploma o el granallado de una medalla.
            $out .= '<filter id="grain' . $u . '" x="0" y="0" width="100%" height="100%">'
                . '<feTurbulence type="fractalNoise" baseFrequency="0.7" numOctaves="3" stitchTiles="stitch"/>'
                . '<feColorMatrix type="saturate" values="0"/></filter>';
        }
        if ($r['vignette'] > 0) {
            // El radio se mide sobre el lienzo, pero lo que hay que oscurecer es
            // el borde de la figura, que está al 45,5%: con un radio mayor la
            // sombra terminaba fuera de la pieza y adentro no se veía nada.
            $out .= '<radialGradient id="vig' . $u . '" cx="50%" cy="50%" r="47%">'
                . '<stop offset="55%" stop-color="#000000" stop-opacity="0"/>'
                . '<stop offset="100%" stop-color="#000000" stop-opacity="' . round($r['vignette'] / 100, 2) . '"/>'
                . '</radialGradient>';
        }

        return $out;
    }

    /** Trama de fondo, recortada a la figura. */
    private function patternDef(array $r): string
    {
        if ($r['pattern'] === 'none') {
            return '';
        }
        $u  = $this->uid;
        $ink = $r['ink'];

        $tile = match ($r['pattern']) {
            'hex' => '<path d="M10 0 L20 5.8 L20 17.3 L10 23 L0 17.3 L0 5.8 Z" fill="none" stroke="' . $ink . '" stroke-width="1"/>',
            'dots' => '<circle cx="6" cy="6" r="1.6" fill="' . $ink . '"/>',
            'diagonal' => '<path d="M-2 10 L10 -2 M0 12 L12 0 M2 14 L14 2" stroke="' . $ink . '" stroke-width="1.4"/>',
            // Guilloché: el entramado de curvas de los billetes y los diplomas.
            'guilloche' => '<path d="M0 20 Q10 0 20 20 T40 20" fill="none" stroke="' . $ink . '" stroke-width="1"/>'
                . '<path d="M0 20 Q10 40 20 20 T40 20" fill="none" stroke="' . $ink . '" stroke-width="1"/>',
            default => '',
        };

        if ($r['pattern'] === 'sunburst') {
            // Rayos desde el centro: no es una baldosa, se dibuja entero.
            $c = self::VIEWBOX / 2;
            $g = '<g id="pat' . $u . '">';
            for ($i = 0; $i < 48; $i++) {
                $a1 = deg2rad($i * 7.5);
                $a2 = deg2rad($i * 7.5 + 3.75);
                $g .= '<path d="M' . $c . ' ' . $c
                    . ' L' . round($c + $c * 1.5 * cos($a1), 1) . ' ' . round($c + $c * 1.5 * sin($a1), 1)
                    . ' L' . round($c + $c * 1.5 * cos($a2), 1) . ' ' . round($c + $c * 1.5 * sin($a2), 1)
                    . ' Z" fill="' . $ink . '"/>';
            }
            return $g . '</g>';
        }

        $size = $r['pattern'] === 'guilloche' ? 40 : ($r['pattern'] === 'hex' ? 20 : 12);
        $h    = $r['pattern'] === 'hex' ? 23 : $size;

        return '<pattern id="pat' . $u . '" width="' . $size . '" height="' . $h
            . '" patternUnits="userSpaceOnUse">' . $tile . '</pattern>';
    }

    /** Camino de la forma elegida, centrado en ($c,$c) con radio $r. */
    private function shapePath(string $shape, float $c, float $r): string
    {
        return match ($shape) {
            'hexagon' => self::polygonPath($c, $r, 6, -90),
            'octagon' => self::polygonPath($c, $r, 8, -112.5),
            'diamond' => self::polygonPath($c, $r, 4, -90),
            'star'    => self::starPath($c, $r, 5, 0.48),
            'rosette' => self::rosettePath($c, $r, 14),
            // Un sello: muchos dientes muy poco profundos. Con `inner` alto la
            // estrella deja de leerse como estrella y pasa a ser un canto
            // moleteado, que es el borde de una moneda.
            'seal'    => self::starPath($c, $r, 22, 0.9),
            'rounded' => self::roundedPath($c, $r),
            'shield'  => self::shieldPath($c, $r),
            default   => 'M ' . ($c - $r) . ' ' . $c
                . ' a ' . $r . ' ' . $r . ' 0 1 0 ' . ($r * 2) . ' 0'
                . ' a ' . $r . ' ' . $r . ' 0 1 0 ' . (-$r * 2) . ' 0 Z',
        };
    }

    /**
     * Giro de la figura, del borde y del recorte.
     *
     * Va como transformación y no como ángulo inicial del polígono porque así
     * alcanza también al círculo, al escudo y al cuadrado, que se dibujan con
     * arcos y no con vértices calculados.
     */
    private function rotAttr(array $r, float $c): string
    {
        return $r['shapeRot'] == 0.0
            ? ''
            : ' transform="rotate(' . round($r['shapeRot'], 1) . ' ' . $c . ' ' . $c . ')"';
    }

    /** Figura que asoma por detrás, girada para que se vea el diente. */
    private function plateElement(array $r, float $c, float $rad): string
    {
        if ($r['plate'] === 'none') {
            return '';
        }
        $pr = $rad * $r['plateScale'] / 100;
        $d  = match ($r['plate']) {
            'star'    => self::starPath($c, $pr, 8, 0.62),
            'rosette' => self::rosettePath($c, $pr, 16),
            'seal'    => self::starPath($c, $pr, 22, 0.9),
            default   => $this->shapePath($r['shape'], $c, $pr),
        };

        // El desfase de 11° es lo que hace que la placa se lea: alineada con la
        // figura de arriba parecería un borde grueso y no una segunda pieza.
        return '<path d="' . $d . '" fill="url(#ringGrad' . $this->uid . ')" opacity=".95"'
            . ' transform="rotate(' . round($r['shapeRot'] + 11, 1) . ' ' . $c . ' ' . $c . ')"/>';
    }

    /** Borde exterior, con sus variantes. */
    private function ringElement(array $r, float $c, float $rad): string
    {
        if ($r['ring'] === 'none') {
            return '';
        }
        $w   = $rad * ($r['ringW'] / 100);
        $out = '<path d="' . $this->shapePath($r['shape'], $c, $rad - $w / 2)
            . '" fill="none" stroke="url(#ringGrad' . $this->uid . ')" stroke-width="' . round($w, 1) . '"/>';

        if ($r['ring'] === 'double') {
            $out .= '<path d="' . $this->shapePath($r['shape'], $c, $rad - $w * 1.5)
                . '" fill="none" stroke="' . self::shade($r['accent'], 0.5) . '" stroke-width="' . round($w * 0.18, 1) . '" opacity=".85"/>';
        } elseif ($r['ring'] === 'beaded') {
            $rr = $rad - $w * 1.6;
            for ($i = 0; $i < 28; $i++) {
                $a = deg2rad($i * (360 / 28));
                $out .= '<circle cx="' . round($c + $rr * cos($a), 1) . '" cy="' . round($c + $rr * sin($a), 1)
                    . '" r="' . round($w * 0.19, 1) . '" fill="' . self::shade($r['accent'], 0.55) . '" opacity=".9"/>';
            }
        } elseif ($r['ring'] === 'notched') {
            $rr = $rad - $w * 1.5;
            for ($i = 0; $i < 36; $i++) {
                $a = deg2rad($i * 10);
                $x1 = $c + ($rr - $w * 0.35) * cos($a);
                $y1 = $c + ($rr - $w * 0.35) * sin($a);
                $x2 = $c + $rr * cos($a);
                $y2 = $c + $rr * sin($a);
                $out .= '<line x1="' . round($x1, 1) . '" y1="' . round($y1, 1) . '" x2="' . round($x2, 1)
                    . '" y2="' . round($y2, 1) . '" stroke="' . self::shade($r['accent'], 0.5)
                    . '" stroke-width="' . round($w * 0.16, 1) . '" opacity=".8"/>';
            }
        }

        return $out;
    }

    /** Ornamentos alrededor del contenido. */
    private function ornamentElement(array $r, float $c, float $vb): string
    {
        $ink = $r['ink'];
        // El ornamento se escala y sube o baja: con una cinta alta o un título
        // largo, la posición fija se cruzaba con el resto.
        // Escalar alrededor del centro: llevar el origen al centro, escalar y
        // volver. Aplicar la escala sola corre la figura hacia una esquina.
        $g   = $r['ornScale'] != 100.0 || $r['ornY'] != 0.0
            ? '<g transform="translate(' . round($c, 1) . ' ' . round($c + $vb * $r['ornY'] / 100, 1) . ') '
              . 'scale(' . round($r['ornScale'] / 100, 3) . ') '
              . 'translate(' . round(-$c, 1) . ' ' . round(-$c, 1) . ')">'
            : '';
        $close = $g !== '' ? '</g>' : '';

        return $g . match ($r['ornament']) {
            'laurel' => $this->laurel($c, $vb, $ink),
            'stars'  => $this->starsRow($c, $vb, $ink),
            'rays'   => $this->rays($c, $vb, $ink),
            'chevron' => '<path d="M ' . ($c - $vb * 0.13) . ' ' . ($vb * 0.665)
                . ' L ' . $c . ' ' . ($vb * 0.705) . ' L ' . ($c + $vb * 0.13) . ' ' . ($vb * 0.665)
                . '" fill="none" stroke="' . $ink . '" stroke-width="3" opacity=".55" stroke-linecap="round"/>',
            default  => '',
        } . $close;
    }

    private function laurel(float $c, float $vb, string $ink): string
    {
        $out = '';
        foreach ([-1, 1] as $side) {
            $x0 = $c + $side * $vb * 0.285;
            $out .= '<g opacity=".75" fill="' . $ink . '">';
            for ($i = 0; $i < 7; $i++) {
                $t  = $i / 6;
                $y  = $vb * (0.34 + $t * 0.30);
                $x  = $x0 - $side * $vb * (0.012 + 0.055 * sin($t * M_PI));
                $rx = $vb * (0.030 - $t * 0.008);
                $ry = $vb * 0.012;
                $rot = $side * (-38 + $t * 52);
                $out .= '<ellipse cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" rx="' . round($rx, 1)
                    . '" ry="' . round($ry, 1) . '" transform="rotate(' . round($rot, 1) . ' ' . round($x, 1) . ' ' . round($y, 1) . ')"/>';
            }
            $out .= '</g>';
        }
        return $out;
    }

    private function starsRow(float $c, float $vb, string $ink): string
    {
        $out = '<g fill="' . $ink . '" opacity=".8">';
        for ($i = -2; $i <= 2; $i++) {
            $x = $c + $i * $vb * 0.062;
            $out .= '<path d="' . self::starPath($x, $vb * 0.020, 5, 0.45, $vb * 0.695) . '"/>';
        }
        return $out . '</g>';
    }

    private function rays(float $c, float $vb, string $ink): string
    {
        $out = '<g stroke="' . $ink . '" opacity=".28" stroke-linecap="round">';
        for ($i = 0; $i < 24; $i++) {
            $a  = deg2rad($i * 15);
            $r1 = $vb * 0.20;
            $r2 = $vb * ($i % 2 === 0 ? 0.335 : 0.295);
            $out .= '<line x1="' . round($c + $r1 * cos($a), 1) . '" y1="' . round($c + $r1 * sin($a), 1)
                . '" x2="' . round($c + $r2 * cos($a), 1) . '" y2="' . round($c + $r2 * sin($a), 1)
                . '" stroke-width="' . ($i % 2 === 0 ? 3 : 1.6) . '"/>';
        }
        return $out . '</g>';
    }

    /** Texto siguiendo el anillo, arriba y abajo. */
    private function arcText(array $r, float $c, float $vb): string
    {
        if ($r['arcTop'] === '' && $r['arcBottom'] === '') {
            return '';
        }
        $rad  = $vb * ($r['arcR'] / 100);
        $font = self::FONTS[$r['font']][0];
        $out  = '<defs>'
            . '<path id="arcT' . $this->uid . '" fill="none" d="M ' . ($c - $rad) . ' ' . $c . ' A ' . $rad . ' ' . $rad . ' 0 0 1 ' . ($c + $rad) . ' ' . $c . '"/>'
            // El de abajo se recorre al revés para que el texto no salga invertido.
            . '<path id="arcB' . $this->uid . '" fill="none" d="M ' . ($c - $rad) . ' ' . $c . ' A ' . $rad . ' ' . $rad . ' 0 0 0 ' . ($c + $rad) . ' ' . $c . '"/>'
            . '</defs>';

        $style = 'font-family:' . $font . ';font-weight:700;letter-spacing:' . round(2 + $r['tracking'], 1)
            . 'px;fill:' . $r['ink'] . ';font-size:' . round($vb * $r['arcSize'] / 100, 1) . 'px';

        if ($r['arcTop'] !== '') {
            $out .= '<text style="' . $style . '"><textPath href="#arcT' . $this->uid . '" startOffset="50%" text-anchor="middle">'
                . e(mb_strtoupper($r['arcTop'])) . '</textPath></text>';
        }
        if ($r['arcBottom'] !== '') {
            $out .= '<text style="' . $style . '"><textPath href="#arcB' . $this->uid . '" startOffset="50%" text-anchor="middle">'
                . e(mb_strtoupper($r['arcBottom'])) . '</textPath></text>';
        }

        return $out;
    }

    /**
     * Iniciales, título y nivel.
     *
     * Cada bloque cae en su lugar salvo que se lo haya movido a mano, en cuyo
     * caso manda la posición guardada. Las posiciones que terminan usándose se
     * publican en `data-layout` del SVG: el editor dibuja ahí sus manijas en vez
     * de recalcular el mismo layout por su cuenta y arriesgarse a diferir.
     *
     * @param array<string,array{x:float,y:float}> $layout Se llena con lo usado.
     */
    private function centerText(array $r, float $c, float $vb, bool $hasLogo, array &$layout = []): string
    {
        $font  = self::FONTS[$r['font']][0];
        // Una sombra corta despega el texto de un fondo con trama o degradado.
        $sh    = $r['textShadow'] ? ' filter="url(#tsh' . $this->uid . ')"' : '';
        $out   = '';
        $mark  = $r['mark']['type'] === 'initials' ? $r['mark']['value'] : '';
        $level = $r['ribbon'] ? '' : $r['level'];

        $blocks = [];
        if ($mark !== '')       { $blocks[] = ['mark', $mark]; }
        if ($r['title'] !== '') { $blocks[] = ['title', $r['title']]; }
        if ($level !== '')      { $blocks[] = ['level', $level]; }
        if ($blocks === []) {
            return '';
        }

        $y = $hasLogo ? $vb * 0.44 : ($r['ribbon'] ? $vb * 0.44 : $vb * 0.47);
        if (count($blocks) === 1 && !$hasLogo) {
            $y = $vb * 0.51;
        }

        foreach ($blocks as [$kind, $text]) {
            $manual = $r['pos'][$kind] ?? null;
            $cx     = $manual !== null ? $manual['x'] * $vb : $c;
            $cy     = $manual !== null ? $manual['y'] * $vb : null;

            if ($kind === 'mark') {
                $size = $vb * 0.155 * $r['markSize'];
                $ty   = $cy ?? $y;
                $out .= '<text x="' . round($cx, 1) . '" y="' . round($ty, 1) . '" text-anchor="middle"' . $sh . ' '
                    . 'style="font-family:' . $font . ';font-weight:800;font-size:' . round($size, 1)
                    . 'px;fill:' . $r['ink'] . ';letter-spacing:' . round($r['tracking'], 1) . 'px">'
                    . e($text) . '</text>';
                $layout['mark'] = ['x' => $cx / $vb, 'y' => $ty / $vb, 'h' => $size / $vb];
                $y = $ty + $size * 0.72;
            } elseif ($kind === 'title') {
                $lines = self::wrap($r['titleCaps'] ? mb_strtoupper($text) : $text, 18, 3);
                $size  = $vb * 0.072 * $r['titleSize'] * (count($lines) > 2 ? 0.86 : 1);
                $ty    = $cy ?? $y;
                foreach ($lines as $i => $line) {
                    $out .= '<text x="' . round($cx, 1) . '" y="' . round($ty + $i * $size * 1.16, 1) . '" text-anchor="middle"' . $sh . ' '
                        . 'style="font-family:' . $font . ';font-weight:700;font-size:' . round($size, 1)
                        . 'px;fill:' . $r['ink'] . ';letter-spacing:' . round($r['tracking'] * 0.5, 1) . 'px">'
                        . e($line) . '</text>';
                }
                $layout['title'] = ['x' => $cx / $vb, 'y' => $ty / $vb, 'h' => count($lines) * $size * 1.16 / $vb];
                $y = $ty + count($lines) * $size * 1.16 + $vb * 0.012;
            } else {
                $size = $vb * 0.052;
                $ty   = ($cy ?? $y) + ($cy === null ? $size * 0.6 : 0);
                $out .= '<text x="' . round($cx, 1) . '" y="' . round($ty, 1) . '" text-anchor="middle"' . $sh . ' '
                    . 'style="font-family:' . $font . ';font-weight:500;font-size:' . round($size, 1)
                    . 'px;fill:' . $r['ink'] . ';opacity:.92;letter-spacing:' . round(1 + $r['tracking'] * 0.5, 1) . 'px">'
                    . e($text) . '</text>';
                $layout['level'] = ['x' => $cx / $vb, 'y' => $ty / $vb, 'h' => $size / $vb];
            }
        }

        return $out;
    }

    /** Estrellas de nivel: una fila bajo el contenido, como grado alcanzado. */
    private function levelStars(array $r, float $c, float $vb): string
    {
        $n    = $r['stars'];
        $size = $vb * 0.030;
        $gap  = $size * 2.6;
        $y    = $r['ribbon'] ? $vb * 0.645 : $vb * 0.695;
        $x0   = $c - ($n - 1) * $gap / 2;

        $out = '<g fill="' . $r['ink'] . '" opacity=".92">';
        for ($i = 0; $i < $n; $i++) {
            $out .= '<path d="' . self::starPath($x0 + $i * $gap, $size, 5, 0.45, $y) . '"/>';
        }

        return $out . '</g>';
    }

    private function ribbon(array $r, float $c, float $vb): string
    {
        $y = $vb * $r['ribbonY'] / 100;
        $h = $vb * 0.115;
        $w = $vb * $r['ribbonW'] / 100;
        $x = $c - $w / 2;
        $n = $h * 0.42;
        $font = self::FONTS[$r['font']][0];

        // La cinta tiene fondo propio: su texto se decide por ese fondo y no por
        // la tinta general, o queda ilegible sobre un aro dorado o plateado.
        $band = $r['ribbonAuto'] ? self::shade($r['accent'], -0.1) : $r['ribbonColor'];
        $ink  = self::readable($band);
        $out  = '';

        if ($r['ribbonStyle'] === 'folded') {
            // Los dobleces que se ven por detrás son lo que da la sensación de
            // que la cinta rodea la pieza en vez de estar apoyada encima.
            $f = self::shade($band, -0.35);
            $out .= '<path d="M ' . round($x - $vb * 0.045, 1) . ' ' . round($y - $h * 0.42, 1)
                . ' l ' . round($vb * 0.05, 1) . ' 0 l 0 ' . round($h * 0.9, 1) . ' Z" fill="' . $f . '"/>'
                . '<path d="M ' . round($x + $w + $vb * 0.045, 1) . ' ' . round($y - $h * 0.42, 1)
                . ' l ' . round(-$vb * 0.05, 1) . ' 0 l 0 ' . round($h * 0.9, 1) . ' Z" fill="' . $f . '"/>';
        }

        $shape = match ($r['ribbonStyle']) {
            'flat' => 'M ' . round($x, 1) . ' ' . round($y, 1) . ' H ' . round($x + $w, 1)
                . ' V ' . round($y + $h, 1) . ' H ' . round($x, 1) . ' Z',
            default => 'M ' . round($x, 1) . ' ' . round($y, 1)
                . ' H ' . round($x + $w, 1) . ' L ' . round($x + $w - $n, 1) . ' ' . round($y + $h / 2, 1)
                . ' L ' . round($x + $w, 1) . ' ' . round($y + $h, 1)
                . ' H ' . round($x, 1) . ' L ' . round($x + $n, 1) . ' ' . round($y + $h / 2, 1) . ' Z',
        };

        return $out . '<path d="' . $shape . '" fill="' . $band . '"/>'
            . '<text x="' . $c . '" y="' . round($y + $h * 0.66, 1) . '" text-anchor="middle" '
            . 'style="font-family:' . $font . ';font-weight:700;font-size:' . round($vb * 0.048, 1)
            . 'px;fill:' . $ink . ';letter-spacing:' . round(1 + $r['tracking'] * 0.5, 1) . 'px">'
            . e(mb_strtoupper($r['level'])) . '</text>';
    }

    // ---------- geometría ----------

    private static function polygonPath(float $c, float $r, int $sides, float $startDeg): string
    {
        $d = '';
        for ($i = 0; $i < $sides; $i++) {
            $a = deg2rad($startDeg + $i * (360 / $sides));
            $d .= ($i === 0 ? 'M ' : ' L ') . round($c + $r * cos($a), 1) . ' ' . round($c + $r * sin($a), 1);
        }
        return $d . ' Z';
    }

    private static function starPath(float $cx, float $r, int $points, float $inner, ?float $cy = null): string
    {
        $cy ??= $cx;
        $d = '';
        for ($i = 0; $i < $points * 2; $i++) {
            $rr = $i % 2 === 0 ? $r : $r * $inner;
            $a  = deg2rad(-90 + $i * (180 / $points));
            $d .= ($i === 0 ? 'M ' : ' L ') . round($cx + $rr * cos($a), 1) . ' ' . round($cy + $rr * sin($a), 1);
        }
        return $d . ' Z';
    }

    /** Roseta: círculo con festón, la silueta clásica de un sello. */
    private static function rosettePath(float $c, float $r, int $lobes): string
    {
        $d = '';
        $steps = $lobes * 12;
        for ($i = 0; $i <= $steps; $i++) {
            $t  = $i / $steps * 2 * M_PI;
            $rr = $r * (0.94 + 0.06 * cos($lobes * $t));
            $d .= ($i === 0 ? 'M ' : ' L ') . round($c + $rr * cos($t), 1) . ' ' . round($c + $rr * sin($t), 1);
        }
        return $d . ' Z';
    }

    private static function roundedPath(float $c, float $r): string
    {
        $h = $r * 0.92;
        $k = $h * 0.3;
        $l = $c - $h;
        $t = $c - $h;
        $w = $h * 2;
        return 'M ' . ($l + $k) . ' ' . $t
            . ' h ' . ($w - 2 * $k) . ' a ' . $k . ' ' . $k . ' 0 0 1 ' . $k . ' ' . $k
            . ' v ' . ($w - 2 * $k) . ' a ' . $k . ' ' . $k . ' 0 0 1 ' . (-$k) . ' ' . $k
            . ' h ' . (-($w - 2 * $k)) . ' a ' . $k . ' ' . $k . ' 0 0 1 ' . (-$k) . ' ' . (-$k)
            . ' v ' . (-($w - 2 * $k)) . ' a ' . $k . ' ' . $k . ' 0 0 1 ' . $k . ' ' . (-$k) . ' Z';
    }

    private static function shieldPath(float $c, float $r): string
    {
        $w = $r * 0.86;
        $t = $c - $r;
        $b = $c + $r;
        $s = $t + $r * 0.62;
        return 'M ' . ($c - $w) . ' ' . $t . ' H ' . ($c + $w) . ' V ' . $s
            . ' Q ' . ($c + $w * 0.92) . ' ' . ($b - $r * 0.1) . ' ' . $c . ' ' . $b
            . ' Q ' . ($c - $w * 0.92) . ' ' . ($b - $r * 0.1) . ' ' . ($c - $w) . ' ' . $s . ' Z';
    }

    // ---------- utilidades ----------

    /**
     * Proporción alto/ancho de un SVG, leída del viewBox o de width/height.
     * Sin esto habría que asumir cuadrado y los logotipos apaisados saldrían
     * estirados.
     */
    private static function svgRatio(string $svg): float
    {
        $head = substr($svg, 0, 2048);
        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[,\s]+[-\d.]+[,\s]+([\d.]+)[,\s]+([\d.]+)/i', $head, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];
            if ($w > 0 && $h > 0) {
                return $h / $w;
            }
        }
        if (preg_match('/\bwidth\s*=\s*["\']([\d.]+)/i', $head, $mw)
            && preg_match('/\bheight\s*=\s*["\']([\d.]+)/i', $head, $mh)
            && (float) $mw[1] > 0) {
            return (float) $mh[1] / (float) $mw[1];
        }

        return 1.0;
    }

    /** @return array<int,string> */
    private static function wrap(string $text, int $perLine, int $maxLines): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $line  = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if (mb_strlen($try) <= $perLine || $line === '') {
                $line = $try;
            } else {
                $lines[] = $line;
                $line    = $w;
            }
            if (count($lines) === $maxLines) {
                break;
            }
        }
        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }
        return $lines === [] ? [$text] : $lines;
    }

    /** Blanco o casi negro según qué se lea mejor sobre $hex. */
    private static function readable(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $lum = (0.299 * hexdec(substr($h, 0, 2))
              + 0.587 * hexdec(substr($h, 2, 2))
              + 0.114 * hexdec(substr($h, 4, 2))) / 255;

        return $lum > 0.6 ? '#101828' : '#ffffff';
    }

    private static function shade(string $hex, float $amount): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $v = (int) hexdec(substr($h, $i * 2, 2));
            $n = $amount >= 0 ? $v + (255 - $v) * $amount : $v * (1 + $amount);
            $out .= str_pad(dechex((int) max(0, min(255, round($n)))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}
