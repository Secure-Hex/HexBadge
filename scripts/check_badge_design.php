<?php

declare(strict_types=1);

/**
 * Verifica el generador de imágenes de acreditación.
 *
 * Uso:
 *   php scripts/check_badge_design.php            # verifica
 *   php scripts/check_badge_design.php --save DIR # además deja las muestras para mirarlas
 */

require __DIR__ . '/../src/bootstrap.php';

use HexBadge\Services\BadgeDesignService;

$saveDir = null;
$idx     = array_search('--save', $argv, true);
if ($idx !== false && isset($argv[$idx + 1])) {
    $saveDir = rtrim((string) $argv[$idx + 1], '/') . '/';
    if (!is_dir($saveDir)) {
        mkdir($saveDir, 0755, true);
    }
}

$fails = 0;
$ok    = static function (string $label, bool $cond, string $detail = '') use (&$fails): void {
    if ($cond) {
        echo "  ✓ $label\n";
        return;
    }
    echo "  ✗ $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    $fails++;
};

echo "== Saneado ==\n";

$empty = BadgeDesignService::sanitize([]);
$esperadas = ['shape', 'fill', 'accent', 'finish', 'ring', 'ribbon', 'logo', 'mark', 'title', 'level'];
$ok('una receta vacía devuelve todas las claves',
    array_diff($esperadas, array_keys($empty)) === [],
    'faltan: ' . implode(',', array_diff($esperadas, array_keys($empty))));
$ok('acabado y borde caen a un valor válido',
    isset(\HexBadge\Services\BadgeDesignService::FINISHES[$empty['finish']])
    && isset(\HexBadge\Services\BadgeDesignService::RINGS[$empty['ring']]),
    $empty['finish'] . '/' . $empty['ring']);
$ok('cae al hexágono por defecto', $empty['shape'] === 'hexagon', $empty['shape']);

$sucia = BadgeDesignService::sanitize([
    'shape'  => '../../etc/passwd',
    'fill'   => '<script>alert(1)</script>',
    'accent' => '#ABCD',                       // 4 dígitos: inválido
    'title'  => str_repeat('a', 500),
    'level'  => "  <b>Nivel</b> 3  ",
    'mark'   => ['type' => 'inexistente', 'value' => '@@'],
]);
$ok('una forma inventada cae al default', $sucia['shape'] === 'hexagon', $sucia['shape']);
$ok('un color inválido cae al default', $sucia['fill'] === '#1565d8', $sucia['fill']);
$ok('un hex de 4 dígitos se rechaza', $sucia['accent'] === '#0f1b2e', $sucia['accent']);
$ok('el título se recorta a 40', mb_strlen($sucia['title']) === 40, (string) mb_strlen($sucia['title']));
$ok('el nivel se limpia de etiquetas', $sucia['level'] === 'Nivel 3', $sucia['level']);
$ok('una marca sin caracteres útiles queda en none', $sucia['mark']['type'] === 'none', $sucia['mark']['type']);

$acentos = BadgeDesignService::sanitize(['mark' => ['type' => 'initials', 'value' => 'ñu']]);
$ok('las iniciales respetan acentos y pasan a mayúscula', $acentos['mark']['value'] === 'ÑU', $acentos['mark']['value']);

echo "\n== Render ==\n";

$svc   = new BadgeDesignService();
$casos = [];
foreach (array_keys(BadgeDesignService::SHAPES) as $shape) {
    $casos["$shape-completo"] = [
        'shape' => $shape, 'fill' => '#1565d8', 'accent' => '#0f1b2e',
        'mark'  => ['type' => 'initials', 'value' => 'SH'],
        'title' => 'Fundamentos de Seguridad Web', 'level' => 'Nivel 2',
    ];
}
$casos['solo-iniciales']  = ['shape' => 'circle', 'mark' => ['type' => 'initials', 'value' => 'IA']];
$casos['solo-titulo']     = ['shape' => 'hexagon', 'title' => 'Pentesting'];
$casos['titulo-larguisimo'] = [
    'shape' => 'hexagon', 'title' => 'Especialización Avanzada en Respuesta a Incidentes',
    'level' => 'Certificación profesional',
];
$casos['palabra-impartible'] = ['shape' => 'circle', 'title' => 'Contrainteligencia'];
$casos['fondo-claro']        = ['shape' => 'rounded', 'fill' => '#f5f5f5', 'accent' => '#cccccc', 'title' => 'Contraste'];
$casos['vacio']              = [];

$peak = 0;
foreach ($casos as $nombre => $receta) {
    $bytes = $svc->render($receta);
    $info  = @getimagesizefromstring($bytes);
    $bien  = is_array($info) && $info[0] === 512 && $info[1] === 512 && $info[2] === IMAGETYPE_WEBP;
    $ok(
        sprintf('%-22s %s', $nombre, $bien ? number_format(strlen($bytes) / 1024, 1) . ' KB' : ''),
        $bien,
        is_array($info) ? "{$info[0]}x{$info[1]} tipo {$info[2]}" : 'no es una imagen'
    );
    $peak = max($peak, memory_get_peak_usage(true));

    if ($saveDir !== null && $bien) {
        file_put_contents($saveDir . $nombre . '.webp', $bytes);
    }
}

echo "\n== Recursos ==\n";
$peakMb = $peak / 1048576;
$ok(sprintf('pico de memoria %.1f MB (tope 48)', $peakMb), $peakMb < 48);

if ($saveDir !== null) {
    echo "\nMuestras en $saveDir\n";
}

echo "\n" . ($fails === 0 ? "TODO BIEN\n" : "$fails comprobación(es) fallaron\n");
exit($fails === 0 ? 0 : 1);
