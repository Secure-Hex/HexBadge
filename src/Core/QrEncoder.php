<?php

declare(strict_types=1);

namespace HexBadge\Core;

use RuntimeException;

/**
 * Generador de códigos QR en PHP puro (sin binarios externos ni exec),
 * apto para hosting compartido / cPanel.
 *
 * Alcance: modo byte, nivel de corrección M, versiones 1–8 (capacidad de
 * datos suficiente para URIs otpauth de ~150 bytes), máscara fija 0.
 * Implementa Reed-Solomon sobre GF(256) e interleaving de bloques.
 *
 * Devuelve una matriz de módulos (0/1). El render a SVG lo hace QrCode.
 */
final class QrEncoder
{
    /** Estructura ECC nivel M por versión: [eccPorBloque, [[nBloques, datosPorBloque], ...]]. */
    private const ECC_M = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
    ];

    /** Posiciones centrales de los patrones de alineación por versión. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26],
        5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
    ];

    /** Bits de remanente por versión (se agregan al final del stream). */
    private const REMAINDER = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0];

    /** @var array<int,int> */
    private static array $expTable = [];
    /** @var array<int,int> */
    private static array $logTable = [];

    /**
     * Codifica $data y devuelve [matriz, tamaño].
     *
     * @return array{0:array<int,array<int,int>>,1:int}
     */
    public static function encode(string $data): array
    {
        self::initGf();

        $version = self::pickVersion(strlen($data));
        [$eccPerBlock, $groups] = self::ECC_M[$version];

        $bits = self::buildBitstream($data, $version, $groups, $eccPerBlock);
        $size = 17 + 4 * $version;

        return [self::buildMatrix($version, $size, $bits), $size];
    }

    private static function pickVersion(int $len): int
    {
        foreach (self::ECC_M as $v => [$ecc, $groups]) {
            $dataCodewords = 0;
            foreach ($groups as [$n, $d]) {
                $dataCodewords += $n * $d;
            }
            // overhead: 4 bits modo + 8 bits cuenta = 12 bits ≈ 2 bytes.
            if ($len + 2 <= $dataCodewords) {
                return $v;
            }
        }
        throw new RuntimeException('Dato demasiado largo para el QR (máx. versión 8).');
    }

    /**
     * Construye el stream final de bits (datos + ECC interleaved + remanente).
     *
     * @param array<int,array<int,int>> $groups
     */
    private static function buildBitstream(string $data, int $version, array $groups, int $eccPerBlock): string
    {
        $totalData = 0;
        foreach ($groups as [$n, $d]) {
            $totalData += $n * $d;
        }

        // 1) Stream de datos: modo byte (0100) + cuenta (8 bits) + bytes.
        $bits = '0100';
        $bits .= str_pad(decbin(strlen($data)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        // Terminador (hasta 4 bits) + relleno a byte.
        $bits .= str_repeat('0', min(4, $totalData * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        // Bytes de relleno alternados.
        $pads = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $totalData * 8) {
            $bits .= $pads[$i % 2];
            $i++;
        }

        // 2) Codewords de datos.
        $dataCw = [];
        foreach (str_split($bits, 8) as $byte) {
            $dataCw[] = bindec($byte);
        }

        // 3) Dividir en bloques, calcular ECC por bloque.
        $blocks   = [];
        $eccBlocks = [];
        $pos = 0;
        foreach ($groups as [$n, $d]) {
            for ($b = 0; $b < $n; $b++) {
                $block = array_slice($dataCw, $pos, $d);
                $pos  += $d;
                $blocks[]    = $block;
                $eccBlocks[] = self::reedSolomon($block, $eccPerBlock);
            }
        }

        // 4) Interleave de datos.
        $out = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        // 5) Interleave de ECC.
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $ecc) {
                if (isset($ecc[$i])) {
                    $out[] = $ecc[$i];
                }
            }
        }

        // 6) Bits + remanente.
        $stream = '';
        foreach ($out as $cw) {
            $stream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $stream .= str_repeat('0', self::REMAINDER[$version]);
        return $stream;
    }

    // ---- Reed-Solomon / GF(256) -------------------------------------

    private static function initGf(): void
    {
        if (self::$expTable !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$expTable[$i] = $x;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d; // polinomio primitivo
            }
        }
        for ($i = 0; $i < 255; $i++) {
            self::$logTable[self::$expTable[$i]] = $i;
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * @param array<int,int> $data
     * @return array<int,int>
     */
    private static function reedSolomon(array $data, int $eccLen): array
    {
        // Polinomio generador.
        $gen = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j]     ^= $coef;
                $next[$j + 1] ^= self::gfMul($coef, self::$expTable[$i]);
            }
            $gen = $next;
        }

        $rem = array_merge($data, array_fill(0, $eccLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $rem[$i];
            if ($coef !== 0) {
                foreach ($gen as $j => $g) {
                    $rem[$i + $j] ^= self::gfMul($g, $coef);
                }
            }
        }
        return array_slice($rem, count($data), $eccLen);
    }

    // ---- Construcción de la matriz ----------------------------------

    /**
     * @return array<int,array<int,int>>
     */
    private static function buildMatrix(int $version, int $size, string $bits): array
    {
        // -1 = libre, 0/1 = módulo fijo o de datos.
        $m   = array_fill(0, $size, array_fill(0, $size, -1));
        $fn  = array_fill(0, $size, array_fill(0, $size, false)); // ¿es módulo de función?

        $setFn = static function (int $r, int $c, int $val) use (&$m, &$fn): void {
            $m[$r][$c]  = $val;
            $fn[$r][$c] = true;
        };

        // Patrones localizadores + separadores en las 3 esquinas.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fr, $fc]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $fr + $r; $cc = $fc + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $border = ($r === 0 || $r === 6) && $c >= 0 && $c <= 6;
                    $border = $border || (($c === 0 || $c === 6) && $r >= 0 && $r <= 6);
                    $center = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $setFn($rr, $cc, ($border || $center) ? 1 : 0);
                }
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if ($fn[6][$i] === false) { $setFn(6, $i, $val); }
            if ($fn[$i][6] === false) { $setFn($i, 6, $val); }
        }

        // Patrones de alineación. Se omiten SOLO las tres posiciones que
        // coinciden con los localizadores de esquina; las que se solapan con
        // el timing sí se dibujan (el patrón de alineación tiene prioridad).
        $centers = self::ALIGN[$version];
        if ($centers !== []) {
            $first = $centers[0];
            $last  = $centers[count($centers) - 1];
            foreach ($centers as $ar) {
                foreach ($centers as $ac) {
                    if (($ar === $first && $ac === $first)
                        || ($ar === $first && $ac === $last)
                        || ($ar === $last && $ac === $first)) {
                        continue;
                    }
                    for ($r = -2; $r <= 2; $r++) {
                        for ($c = -2; $c <= 2; $c++) {
                            $ring = max(abs($r), abs($c));
                            $setFn($ar + $r, $ac + $c, ($ring !== 1) ? 1 : 0);
                        }
                    }
                }
            }
        }

        // Módulo oscuro.
        $setFn($size - 8, 8, 1);

        // Reservar áreas de formato (se rellenan luego).
        for ($i = 0; $i < 9; $i++) {
            if (!$fn[8][$i]) { $setFn(8, $i, 0); }
            if (!$fn[$i][8]) { $setFn($i, 8, 0); }
        }
        for ($i = 0; $i < 8; $i++) {
            if (!$fn[8][$size - 1 - $i]) { $setFn(8, $size - 1 - $i, 0); }
            if (!$fn[$size - 1 - $i][8]) { $setFn($size - 1 - $i, 8, 0); }
        }

        // Reservar info de versión (v7+).
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $setFn($size - 11 + $j, $i, 0);
                    $setFn($i, $size - 11 + $j, 0);
                }
            }
        }

        // Colocar datos en zigzag, aplicando máscara 0.
        $bitLen = strlen($bits);
        $idx = 0;
        $up  = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) { $col = 5; } // saltar columna de timing
            for ($i = 0; $i < $size; $i++) {
                $row = $up ? ($size - 1 - $i) : $i;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if ($fn[$row][$c]) {
                        continue;
                    }
                    $bit = ($idx < $bitLen) ? (int) $bits[$idx] : 0;
                    $idx++;
                    // Máscara 0: invertir si (row+col) % 2 == 0.
                    if (($row + $c) % 2 === 0) {
                        $bit ^= 1;
                    }
                    $m[$row][$c] = $bit;
                }
            }
            $up = !$up;
        }

        // Info de formato (ECC M + máscara 0).
        self::placeFormat($m, $size);
        if ($version >= 7) {
            self::placeVersion($m, $size, $version);
        }

        // Normalizar: cualquier -1 restante a 0.
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === -1) { $m[$r][$c] = 0; }
            }
        }
        return $m;
    }

    /**
     * @param array<int,array<int,int>> $m
     */
    private static function placeFormat(array &$m, int $size): void
    {
        $format = self::formatBits(0b00, 0); // ECC M = 00, máscara 0
        $bits   = [];
        for ($i = 14; $i >= 0; $i--) {
            $bits[] = ($format >> $i) & 1;
        }
        // Copia 1: alrededor del localizador superior-izquierdo.
        $coords1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        foreach ($coords1 as $i => [$r, $c]) {
            $m[$r][$c] = $bits[$i];
        }
        // Copia 2: bajo el superior-derecho y a la derecha del inferior-izq.
        for ($i = 0; $i < 8; $i++) {
            $m[$size - 1 - $i][8] = $bits[$i];
        }
        for ($i = 8; $i < 15; $i++) {
            $m[8][$size - 15 + $i] = $bits[$i];
        }
    }

    /**
     * @param array<int,array<int,int>> $m
     */
    private static function placeVersion(array &$m, int $size, int $version): void
    {
        $info = self::versionBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($info >> $i) & 1;
            $r = intdiv($i, 3);
            $c = $i % 3;
            $m[$r][$size - 11 + $c] = $bit;
            $m[$size - 11 + $c][$r] = $bit;
        }
    }

    private static function formatBits(int $ecc, int $mask): int
    {
        $data = ($ecc << 3) | $mask;          // 5 bits
        $rem  = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x537 << ($i - 10);    // generador BCH(15,5)
            }
        }
        return (($data << 10) | $rem) ^ 0x5412; // máscara del estándar
    }

    private static function versionBits(int $version): int
    {
        $rem = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x1f25 << ($i - 12);   // generador BCH(18,6)
            }
        }
        return ($version << 12) | $rem;
    }
}
