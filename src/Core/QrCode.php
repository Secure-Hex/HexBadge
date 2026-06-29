<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Genera códigos QR como SVG inline usando QrEncoder (PHP puro).
 *
 * No usa binarios externos, ni exec, ni servicios de terceros: funciona en
 * hosting compartido / cPanel. El secreto TOTP nunca sale del servidor.
 */
final class QrCode
{
    /**
     * Devuelve el SVG (markup) del QR para $data, o null si no se pudo generar.
     */
    public static function svg(string $data, int $quiet = 4): ?string
    {
        try {
            [$m, $size] = QrEncoder::encode($data);
        } catch (\Throwable) {
            return null;
        }

        $dim = $size + 2 * $quiet;
        // Un único path con todos los módulos oscuros (compacto).
        $path = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === 1) {
                    $path .= 'M' . ($c + $quiet) . ',' . ($r + $quiet) . 'h1v1h-1z';
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" '
            . 'shape-rendering="crispEdges" width="100%%" height="100%%" role="img" aria-label="Código QR">'
            . '<rect width="%d" height="%d" fill="#fff"/>'
            . '<path d="%s" fill="#000"/></svg>',
            $dim,
            $dim,
            $dim,
            $dim,
            $path
        );
    }

    /**
     * Rinde el QR como imagen GD (PNG) para incrustar en el certificado.
     * Cada módulo se dibuja como un bloque de $scale píxeles, con zona de
     * silencio (quiet zone). Devuelve un recurso/objeto GdImage, o null.
     *
     * @return \GdImage|null
     */
    public static function gd(string $data, int $scale = 8, int $quiet = 4): ?\GdImage
    {
        try {
            [$m, $size] = QrEncoder::encode($data);
        } catch (\Throwable) {
            return null;
        }

        $dim = ($size + 2 * $quiet) * $scale;
        $img = imagecreatetruecolor($dim, $dim);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $dim, $dim, $white);

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === 1) {
                    $x = ($c + $quiet) * $scale;
                    $y = ($r + $quiet) * $scale;
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }
        return $img;
    }
}
