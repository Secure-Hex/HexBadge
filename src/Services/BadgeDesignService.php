<?php

declare(strict_types=1);

namespace HexBadge\Services;

use GdImage;

/**
 * Genera la imagen de una acreditación a partir de una receta, para quien no
 * llega con una insignia ya diseñada.
 *
 * El catálogo de formas vive acá y no en la base: crecer no necesita migración,
 * y la lista blanca de `sanitize()` hace de estrategia de migración —una forma
 * retirada del catálogo cae al valor por defecto sola.
 *
 * Un único `render()` sirve tanto a la vista previa del formulario como al
 * archivo que se guarda, así que no puede haber diferencia entre lo que se ve y
 * lo que queda. El marcador de diplomas tiene dos algoritmos de ajuste
 * distintos para el mismo texto y por eso el PDF no coincide con la pantalla.
 */
final class BadgeDesignService
{
    /** Lado de la imagen final, en píxeles. */
    private const SIZE = 512;

    /**
     * Factor de supersampling. Las formas se dibujan a 3x y se reducen: GD no
     * antialiasa polígonos rellenos, e `imageantialias()` no sirve acá porque
     * solo afecta trazos sin relleno y no compone con alfa.
     *
     * ponytail: 3 y no 4. A 4x el buffer son 16,8 MB por render, y esto corre en
     * hosting compartido; a 3x son 9,4 MB y el borde ya sale limpio.
     */
    private const SS = 3;

    /** @var array<string,string> Catálogo de formas: clave => etiqueta visible. */
    public const SHAPES = [
        'hexagon' => 'Hexágono',
        'circle'  => 'Círculo',
        'shield'  => 'Escudo',
        'rounded' => 'Cuadrado redondeado',
    ];

    /** Acabado de la superficie: de plano a con relieve. */
    public const FINISHES = [
        'bevel'    => 'Con relieve',
        'gradient' => 'Degradado',
        'flat'     => 'Plano',
    ];

    /** Tratamiento del borde exterior. */
    public const RINGS = [
        'double' => 'Doble',
        'single' => 'Simple',
        'none'   => 'Sin borde',
    ];

    /** Paleta sugerida; el color es libre, esto solo alimenta los atajos de la interfaz. */
    public const PALETTE = [
        '#1565d8' => 'Azul',
        '#0f766e' => 'Verde azulado',
        '#7c3aed' => 'Violeta',
        '#b45309' => 'Ámbar',
        '#be123c' => 'Rojo',
        '#0f1b2e' => 'Azul noche',
    ];

    private const FONT_TITLE = BASE_PATH . '/lib/fonts/PublicSans-Bold.ttf';
    private const FONT_LEVEL = BASE_PATH . '/lib/fonts/PublicSans-Regular.ttf';

    /**
     * Normaliza una receta venida de fuera. Nunca lanza: la vista previa y el
     * guardado tienen que ser infalibles, así que toda entrada inválida cae a un
     * valor por defecto en vez de cortar el flujo. La validación que sí rechaza
     * vive en Core\Validator, no acá.
     *
     * @param  array<string,mixed> $in
     * @return array{shape:string,fill:string,accent:string,mark:array{type:string,value:string},title:string,level:string}
     */
    public static function sanitize(array $in): array
    {
        $hex = static function (mixed $v, string $default): string {
            $v = ltrim((string) $v, '#');
            // Estricto 3 o 6 dígitos: un {3,6} deja pasar 4 y 5, que después no
            // se pueden expandir a RGB y terminan en gris sin que nadie avise.
            return preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) === 1
                ? '#' . strtolower($v)
                : $default;
        };
        // Recorta sin partir la última palabra: "Certificación profesional" a 24
        // caracteres daba "Certificación profesiona", que se lee como un error
        // de tipeo del emisor.
        $text = static function (mixed $v, int $max): string {
            $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
            if (mb_strlen($t) <= $max) {
                return $t;
            }
            $cut = mb_substr($t, 0, $max);
            $sp  = mb_strrpos($cut, ' ');
            // Con espacio se corta ahí aunque se pierda bastante: "Certificación"
            // se lee bien y "Certificación profesiona" parece un error de tipeo.
            return rtrim($sp !== false ? mb_substr($cut, 0, $sp) : $cut);
        };

        $markIn = is_array($in['mark'] ?? null) ? $in['mark'] : [];
        $type   = in_array($markIn['type'] ?? '', ['none', 'initials'], true)
            ? (string) $markIn['type']
            : 'none';

        $value = '';
        if ($type === 'initials') {
            $raw   = preg_replace('/[^\p{L}\p{N}]/u', '', (string) ($markIn['value'] ?? '')) ?? '';
            $value = mb_strtoupper(mb_substr($raw, 0, 2));
            if ($value === '') {
                $type = 'none';
            }
        }

        return [
            'shape'  => isset(self::SHAPES[$in['shape'] ?? '']) ? (string) $in['shape'] : 'hexagon',
            'fill'   => $hex($in['fill']   ?? '', '#1565d8'),
            'accent' => $hex($in['accent'] ?? '', '#0f1b2e'),
            'finish' => isset(self::FINISHES[$in['finish'] ?? '']) ? (string) $in['finish'] : 'bevel',
            'ring'   => isset(self::RINGS[$in['ring'] ?? '']) ? (string) $in['ring'] : 'double',
            'ribbon' => !empty($in['ribbon']),
            'logo'   => !empty($in['logo']),
            'mark'   => ['type' => $type, 'value' => $value],
            // 40 y 24 no son cifras redondas: dentro de la forma el ancho útil
            // es ~300px, y a partir de ahí la tipografía baja de 20px y deja de
            // leerse. El tope de longitud hace de motor de layout.
            'title'  => $text($in['title'] ?? '', 40),
            'level'  => $text($in['level'] ?? '', 24),
        ];
    }

    /**
     * Dibuja la receta y devuelve los bytes WebP de la imagen final.
     *
     * @param array<string,mixed> $recipe Receta ya saneada.
     */
    public function render(array $recipe, ?string $logoPath = null): string
    {
        $r = self::sanitize($recipe);
        $s = self::SIZE * self::SS;

        $big = imagecreatetruecolor($s, $s);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagealphablending($big, true);

        $cx = $cy = $s / 2;
        $r0 = $s * 0.455;

        $fill   = self::rgb($r['fill']);
        $accent = self::rgb($r['accent']);

        // Aro exterior. El doble deja una línea clara entre los dos anillos, que
        // es lo que da sensación de canto metálico en vez de borde dibujado.
        $inner = $r0;
        if ($r['ring'] !== 'none') {
            $this->fillShape($big, $r['shape'], $cx, $cy, $r0, $accent, self::shade($accent, -0.25));
            $inner = $r0 * 0.895;
            if ($r['ring'] === 'double') {
                $this->fillShape($big, $r['shape'], $cx, $cy, $inner, self::shade($accent, 0.55), null);
                $inner *= 0.975;
            }
        }

        // Cuerpo. En plano es un color; si no, un degradado vertical del tono
        // aclarado al oscurecido: es lo que hace que la pieza deje de leerse
        // como un recorte de papel.
        $top = $r['finish'] === 'flat' ? $fill : self::shade($fill, 0.20);
        $bot = $r['finish'] === 'flat' ? $fill : self::shade($fill, -0.22);
        $this->fillShape($big, $r['shape'], $cx, $cy, $inner, $top, $bot);

        if ($r['finish'] === 'bevel') {
            // Luz rasante en el borde superior interno y sombra en el inferior:
            // dos capas muy tenues que insinúan el bisel sin dibujarlo.
            $this->rimLight($big, $r['shape'], $cx, $cy, $inner);
        }

        $img = ImageService::downscale($big, self::SIZE);

        // Sombra proyectada: separa la insignia del fondo sobre el que se
        // muestre. Va en su propia capa porque el desenfoque de GD actúa sobre
        // la imagen entera.
        $img = $this->withDropShadow($img, $r['shape']);

        $ink = imagecolorallocate($img, ...self::inkFor($r['finish'] === 'flat' ? $r['fill'] : self::hex(self::shade($fill, -0.05))));

        if ($r['logo'] && $logoPath !== null && is_file($logoPath)) {
            $this->drawLogo($img, $logoPath, $r);
        }

        $this->drawContent($img, $r, $ink);

        if ($r['ribbon'] && $r['level'] !== '') {
            $this->drawRibbon($img, $r);
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);

        ob_start();
        imagewebp($img, null, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : 100);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Rellena una forma con color plano o con un degradado vertical.
     *
     * Se rellena por líneas de barrido —para cada fila se calculan los cruces
     * con las aristas y se pintan los tramos de adentro— porque GD no tiene
     * degradados ni recorte por forma. Es lo que permite que el degradado
     * respete el contorno de cualquiera de las figuras.
     *
     * @param array{0:int,1:int,2:int}      $c1
     * @param array{0:int,1:int,2:int}|null $c2 null = color plano
     */
    private function fillShape(GdImage $im, string $shape, float $cx, float $cy, float $r, array $c1, ?array $c2): void
    {
        $pts = $this->points($shape, $cx, $cy, $r);
        if ($c2 === null) {
            imagefilledpolygon($im, $pts, imagecolorallocate($im, ...$c1));
            return;
        }

        $ys = [];
        for ($i = 1, $n = count($pts); $i < $n; $i += 2) {
            $ys[] = $pts[$i];
        }
        $yMin = (int) floor(min($ys));
        $yMax = (int) ceil(max($ys));
        $span = max(1, $yMax - $yMin);

        for ($y = $yMin; $y <= $yMax; $y++) {
            $xs = $this->crossings($pts, $y + 0.5);
            if ($xs === []) {
                continue;
            }
            $t = ($y - $yMin) / $span;
            $c = imagecolorallocate(
                $im,
                (int) round($c1[0] + ($c2[0] - $c1[0]) * $t),
                (int) round($c1[1] + ($c2[1] - $c1[1]) * $t),
                (int) round($c1[2] + ($c2[2] - $c1[2]) * $t)
            );
            for ($i = 0; $i + 1 < count($xs); $i += 2) {
                imagefilledrectangle($im, (int) round($xs[$i]), $y, (int) round($xs[$i + 1]), $y, $c);
            }
        }
    }

    /**
     * Cruces ordenados de la recta y = $y con las aristas del polígono.
     *
     * @param  array<int,int> $pts
     * @return array<int,float>
     */
    private function crossings(array $pts, float $y): array
    {
        $xs = [];
        $n  = count($pts) / 2;
        for ($i = 0; $i < $n; $i++) {
            $j  = ($i + 1) % $n;
            $x1 = $pts[$i * 2];
            $y1 = $pts[$i * 2 + 1];
            $x2 = $pts[$j * 2];
            $y2 = $pts[$j * 2 + 1];
            if (($y1 <= $y && $y2 > $y) || ($y2 <= $y && $y1 > $y)) {
                $xs[] = $x1 + ($y - $y1) / ($y2 - $y1) * ($x2 - $x1);
            }
        }
        sort($xs);

        return $xs;
    }

    /** Luz en el canto superior y sombra en el inferior, ambas muy tenues. */
    private function rimLight(GdImage $im, string $shape, float $cx, float $cy, float $r): void
    {
        $pts  = $this->points($shape, $cx, $cy, $r);
        $ys   = [];
        for ($i = 1, $n = count($pts); $i < $n; $i += 2) {
            $ys[] = $pts[$i];
        }
        $yMin = (int) floor(min($ys));
        $yMax = (int) ceil(max($ys));
        $span = max(1, $yMax - $yMin);

        for ($y = $yMin; $y <= $yMax; $y++) {
            $t = ($y - $yMin) / $span;
            // Blanco arriba, negro abajo; opacidad máxima en los extremos y nula
            // en el medio, para que no se vea una banda.
            if ($t < 0.30) {
                $a = (int) round(112 + 15 * ($t / 0.30));   // 112..127
                $c = imagecolorallocatealpha($im, 255, 255, 255, $a);
            } elseif ($t > 0.72) {
                $a = (int) round(127 - 18 * (($t - 0.72) / 0.28));
                $c = imagecolorallocatealpha($im, 0, 0, 0, $a);
            } else {
                continue;
            }
            $xs = $this->crossings($pts, $y + 0.5);
            for ($i = 0; $i + 1 < count($xs); $i += 2) {
                imagefilledrectangle($im, (int) round($xs[$i]), $y, (int) round($xs[$i + 1]), $y, $c);
            }
        }
    }

    /** Devuelve una copia con sombra proyectada debajo de la pieza. */
    private function withDropShadow(GdImage $img, string $shape): GdImage
    {
        $size = self::SIZE;
        $out  = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

        $shadow = imagecreatetruecolor($size, $size);
        imagealphablending($shadow, false);
        imagesavealpha($shadow, true);
        imagefill($shadow, 0, 0, imagecolorallocatealpha($shadow, 0, 0, 0, 127));
        imagealphablending($shadow, true);
        imagefilledpolygon(
            $shadow,
            $this->points($shape, $size / 2, $size / 2 + $size * 0.022, $size * 0.452),
            imagecolorallocatealpha($shadow, 15, 27, 46, 96)
        );
        for ($i = 0; $i < 4; $i++) {
            imagefilter($shadow, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagealphablending($out, true);
        imagecopy($out, $shadow, 0, 0, 0, 0, $size, $size);
        imagecopy($out, $img, 0, 0, 0, 0, $size, $size);
        imagedestroy($shadow);
        imagedestroy($img);

        return $out;
    }

    /** Logo del emisor, centrado arriba dentro de la pieza. */
    private function drawLogo(GdImage $img, string $path, array $r): void
    {
        $src = @imagecreatefromstring((string) @file_get_contents($path));
        if (!$src instanceof GdImage) {
            return;
        }
        $box = self::SIZE * ($r['title'] !== '' || $r['level'] !== '' ? 0.22 : 0.34);
        $sw  = imagesx($src);
        $sh  = imagesy($src);
        $sc  = min($box / $sw, $box / $sh);
        $dw  = (int) round($sw * $sc);
        $dh  = (int) round($sh * $sc);

        imagealphablending($img, true);
        imagecopyresampled(
            $img, $src,
            (int) round((self::SIZE - $dw) / 2),
            (int) round(self::SIZE * 0.235 - $dh / 2),
            0, 0, $dw, $dh, $sw, $sh
        );
        imagedestroy($src);
    }

    /** Cinta inferior con el nivel, el recurso más reconocible de una insignia. */
    private function drawRibbon(GdImage $img, array $r): void
    {
        $size = self::SIZE;
        $accent = self::rgb($r['accent']);
        // Cada forma tiene su propio ancho a esa altura: en el hexágono la cinta
        // se salía por los costados porque la figura ya está cerrando.
        [$y, $w] = match ($r['shape']) {
            'circle'  => [(int) round($size * 0.700), (int) round($size * 0.90)],
            'shield'  => [(int) round($size * 0.640), (int) round($size * 0.74)],
            'rounded' => [(int) round($size * 0.715), (int) round($size * 0.92)],
            default   => [(int) round($size * 0.665), (int) round($size * 0.84)],
        };
        $h      = (int) round($size * 0.115);
        $x      = (int) round(($size - $w) / 2);
        $notch  = (int) round($h * 0.42);

        imagealphablending($img, true);
        // Cuerpo con las puntas en cola de milano.
        imagefilledpolygon($img, [
            $x, $y,
            $x + $w, $y,
            $x + $w - $notch, $y + $h / 2,
            $x + $w, $y + $h,
            $x, $y + $h,
            $x + $notch, $y + $h / 2,
        ], imagecolorallocate($img, ...self::shade($accent, -0.12)));

        $ink = imagecolorallocate($img, ...self::inkFor(self::hex(self::shade($accent, -0.12))));
        $this->fitted($img, $r['level'], self::FONT_TITLE, $size / 2, $y + $h / 2, $w * 0.62, $h * 0.52, $ink, 1);
    }

    /** @return array<int,int> Puntos de la forma pedida. */
    private function points(string $shape, float $cx, float $cy, float $r): array
    {
        return match ($shape) {
            // El círculo también es un polígono: así el relleno por líneas de
            // barrido, el degradado y la sombra son un único camino de código.
            'circle'  => self::polygonPoints($cx, $cy, $r, 72, -90.0),
            'shield'  => self::shieldPoints($cx, $cy, $r),
            'rounded' => self::roundedPoints($cx, $cy, $r),
            default   => self::polygonPoints($cx, $cy, $r, 6, -90.0),
        };
    }

    /**
     * Aclara ($amount > 0) u oscurece ($amount < 0) un color.
     *
     * @param  array{0:int,1:int,2:int} $c
     * @return array{0:int,1:int,2:int}
     */
    private static function shade(array $c, float $amount): array
    {
        $f = static function (int $v) use ($amount): int {
            $n = $amount >= 0 ? $v + (255 - $v) * $amount : $v * (1 + $amount);
            return (int) max(0, min(255, round($n)));
        };
        return [$f($c[0]), $f($c[1]), $f($c[2])];
    }

    /** @param array{0:int,1:int,2:int} $c */
    private static function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    /**
     * Caja de texto segura de cada forma, en fracciones del lado.
     *
     * `w` es el ancho aprovechable y `cy` el centro del bloque: una forma que se
     * angosta hacia abajo, como el escudo, necesita subir el texto y usar menos
     * ancho que un círculo. Con un valor único para todas, el texto se salía por
     * los costados en el hexágono y en el escudo.
     *
     * @return array{w:float,cy:float}
     */
    private static function textBox(string $shape): array
    {
        return match ($shape) {
            'circle'  => ['w' => 0.60, 'cy' => 0.50],
            'rounded' => ['w' => 0.64, 'cy' => 0.50],
            'shield'  => ['w' => 0.52, 'cy' => 0.45],
            default   => ['w' => 0.54, 'cy' => 0.50],   // hexágono
        };
    }

    /**
     * Coloca iniciales, título y nivel dentro de la forma.
     *
     * @param array<string,mixed> $r
     */
    private function drawContent(GdImage $img, array $r, int $ink): void
    {
        $size = self::SIZE;
        $box  = self::textBox($r['shape']);
        if ($r['ribbon'] && $r['level'] !== '') {
            $box['cy'] -= 0.06;   // deja aire para la cinta
        }
        $bw   = $size * $box['w'];
        $cx   = $size / 2;

        $mark  = $r['mark']['type'] === 'initials' && $r['mark']['value'] !== '' ? $r['mark']['value'] : '';
        $title = $r['title'];
        // Con cinta, el nivel se dibuja ahí y no en el cuerpo: si no, aparece
        // repetido dos veces en la misma insignia.
        $level = $r['ribbon'] ? '' : $r['level'];

        // Alturas relativas de cada pieza presente, y el aire entre ellas.
        $parts = [];
        if ($mark !== '')  { $parts[] = ['t' => 'mark',  'h' => 0.170]; }
        if ($title !== '') { $parts[] = ['t' => 'title', 'h' => $level !== '' ? 0.150 : 0.180]; }
        if ($level !== '') { $parts[] = ['t' => 'level', 'h' => 0.075]; }
        if ($parts === []) {
            return;
        }

        $gap   = 0.045;
        $total = array_sum(array_column($parts, 'h')) + $gap * (count($parts) - 1);
        $y     = $box['cy'] - $total / 2;

        foreach ($parts as $p) {
            $h  = $p['h'];
            $cy = ($y + $h / 2) * $size;
            match ($p['t']) {
                'mark'  => $this->fitted($img, $mark, self::FONT_TITLE, $cx, $cy, $bw * 0.55, $size * $h, $ink, 1),
                'title' => $this->fitted($img, $title, self::FONT_TITLE, $cx, $cy, $bw, $size * $h, $ink, 2),
                'level' => $this->fitted($img, $level, self::FONT_LEVEL, $cx, $cy, $bw, $size * $h, $ink, 1),
            };
            $y += $h + $gap;
        }
    }

    /**
     * Escribe $text centrado en ($cx,$cy), con el tamaño más grande que entre en
     * $bw x $bh usando como mucho $maxLines líneas.
     *
     * ponytail: corte por palabras, sin guionado ni justificado. Si una sola
     * palabra no entra, se achica hasta que entre.
     */
    private function fitted(
        GdImage $img,
        string $text,
        string $font,
        float $cx,
        float $cy,
        float $bw,
        float $bh,
        int $ink,
        int $maxLines
    ): void {
        $text = trim($text);
        if ($text === '' || !is_file($font)) {
            return;
        }

        // Arranca suponiendo que se usarán todas las líneas permitidas.
        $size  = $bh / $maxLines;
        $lines = null;

        // Mismo criterio que el resto del proyecto: bajar ~7% por vuelta.
        for ($i = 0; $i < 40; $i++) {
            $candidate = $this->wrap($text, $font, $size, $bw, $maxLines);
            if ($candidate !== null && count($candidate) * $size * 1.15 <= $bh) {
                $lines = $candidate;
                break;
            }
            $size *= 0.93;
            if ($size < 6.0) {
                $size  = 6.0;
                $lines = $this->wrap($text, $font, $size, $bw, $maxLines) ?? [$text];
                break;
            }
        }
        if ($lines === null) {
            $lines = [$text];
        }

        $lineH = $size * 1.15;
        $top   = $cy - (count($lines) * $lineH) / 2;

        foreach ($lines as $n => $line) {
            $bbox = imagettfbbox($size, 0, $font, $line);
            $tw   = abs($bbox[2] - $bbox[0]);
            $x    = $cx - $tw / 2 - $bbox[0];
            // El baseline se corrige con el bbox real: centrar por alto nominal
            // desalinea las mayúsculas con acento y las letras con descendente.
            $y = $top + $n * $lineH + $lineH / 2 - ($bbox[7] + $bbox[1]) / 2;
            imagettftext($img, $size, 0, (int) round($x), (int) round($y), $ink, $font, $line);
        }
    }

    /**
     * Reparte $text en como mucho $maxLines líneas que entren en $bw.
     * Devuelve null si no entra ni partiendo por palabras.
     *
     * @return array<int,string>|null
     */
    private function wrap(string $text, string $font, float $size, float $bw, int $maxLines): ?array
    {
        $width = static function (string $s) use ($size, $font): float {
            $b = imagettfbbox($size, 0, $font, $s);
            return abs($b[2] - $b[0]);
        };

        if ($maxLines === 1) {
            return $width($text) <= $bw ? [$text] : null;
        }

        $lines = [];
        $line  = '';
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($width($try) <= $bw) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
            if ($width($line) > $bw) {
                return null; // una palabra sola no entra: hay que achicar
            }
            if (count($lines) >= $maxLines) {
                return null;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return count($lines) <= $maxLines ? $lines : null;
    }

    /** @return array<int,int> Puntos planos [x0,y0,x1,y1,...] como espera GD. */
    private static function polygonPoints(float $cx, float $cy, float $r, int $sides, float $startDeg): array
    {
        $pts = [];
        for ($i = 0; $i < $sides; $i++) {
            $a     = deg2rad($startDeg + $i * (360 / $sides));
            $pts[] = (int) round($cx + $r * cos($a));
            $pts[] = (int) round($cy + $r * sin($a));
        }
        return $pts;
    }

    /** Escudo: hombros rectos arriba y dos curvas que bajan a una punta. */
    private static function shieldPoints(float $cx, float $cy, float $r): array
    {
        $w   = $r * 0.86;
        $top = $cy - $r;
        $bot = $cy + $r;
        $sh  = $top + $r * 0.62;   // dónde arrancan las curvas

        $pts = [
            (int) round($cx - $w), (int) round($top),
            (int) round($cx + $w), (int) round($top),
            (int) round($cx + $w), (int) round($sh),
        ];
        // Curva derecha → punta, aproximada con una cuadrática discreta: a 1536px
        // y reducida a 512, 10 tramos ya son imperceptibles.
        for ($i = 1; $i <= 10; $i++) {
            $t     = $i / 10;
            $x     = (1 - $t) ** 2 * ($cx + $w) + 2 * (1 - $t) * $t * ($cx + $w * 0.9) + $t ** 2 * $cx;
            $y     = (1 - $t) ** 2 * $sh + 2 * (1 - $t) * $t * ($bot - $r * 0.1) + $t ** 2 * $bot;
            $pts[] = (int) round($x);
            $pts[] = (int) round($y);
        }
        for ($i = 1; $i <= 10; $i++) {
            $t     = $i / 10;
            $x     = (1 - $t) ** 2 * $cx + 2 * (1 - $t) * $t * ($cx - $w * 0.9) + $t ** 2 * ($cx - $w);
            $y     = (1 - $t) ** 2 * $bot + 2 * (1 - $t) * $t * ($bot - $r * 0.1) + $t ** 2 * $sh;
            $pts[] = (int) round($x);
            $pts[] = (int) round($y);
        }

        return $pts;
    }

    /** Cuadrado de esquinas redondeadas, como polígono para reusar un solo camino. */
    private static function roundedPoints(float $cx, float $cy, float $r): array
    {
        $half   = $r * 0.92;
        $radius = $half * 0.28;
        $inner  = $half - $radius;
        $pts    = [];

        // Cuatro esquinas, cada una un cuarto de vuelta en 6 tramos.
        foreach ([[1, 1, 0], [-1, 1, 90], [-1, -1, 180], [1, -1, 270]] as [$sx, $sy, $from]) {
            $ox = $cx + $sx * $inner;
            $oy = $cy + $sy * $inner;
            for ($i = 0; $i <= 6; $i++) {
                $a     = deg2rad($from + ($i / 6) * 90);
                $pts[] = (int) round($ox + $radius * cos($a));
                $pts[] = (int) round($oy + $radius * sin($a));
            }
        }

        return $pts;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /**
     * Color de texto derivado del relleno. Es automático a propósito: dejarlo
     * elegir es el camino más corto a texto claro sobre fondo claro.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function inkFor(string $fill): array
    {
        [$r, $g, $b] = self::rgb($fill);
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $lum > 0.6 ? [15, 27, 46] : [255, 255, 255];
    }
}
