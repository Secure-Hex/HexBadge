<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\QrCode;
use HexBadge\Models\Font;
use HexBadge\Models\IssuedBadge;

/**
 * Generación de certificados/diplomas en PDF a partir de una plantilla de
 * imagen marcada por el admin.
 *
 * Pipeline: GD compone (plantilla + textos TTF + QR) -> JPEG -> envoltorio PDF
 * de una página. Todo PHP puro (GD/FreeType), sin Composer ni binarios.
 */
final class CertificateService
{
    private const TEMPLATE_DIR = BASE_PATH . '/apps/earner/public/uploads/certificates/';
    private const CACHE_DIR     = BASE_PATH . '/storage/certificates/';

    /** Campos de texto soportados, en orden de dibujo. */
    private const TEXT_FIELDS = ['course', 'name', 'date', 'cert_id'];

    /**
     * ¿El template tiene un certificado configurado (plantilla + marcas requeridas)?
     *
     * @param array<string,mixed> $template Fila con certificate_filename/certificate_config.
     */
    public static function hasCertificate(array $template): bool
    {
        if (empty($template['certificate_filename'])) {
            return false;
        }
        $cfg = self::decodeConfig($template['certificate_config'] ?? null);
        foreach (['name', 'qr', 'cert_id', 'date'] as $req) {
            if (!isset($cfg[$req])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Genera (o devuelve de cache) el PDF del certificado de un badge.
     * Devuelve la ruta absoluta al PDF, o null si el template no tiene certificado.
     */
    public function generate(string $badgeUuid): ?string
    {
        $badge = IssuedBadge::findFullByUuid($badgeUuid);
        if ($badge === null || !self::hasCertificate($badge)) {
            return null;
        }

        $cache = self::CACHE_DIR . $badgeUuid . '.pdf';

        // Usar cache si existe y es más nuevo que el template.
        if (is_file($cache) && is_readable($cache) && filesize($cache) > 0) {
            $tplTs = strtotime((string) ($badge['template_updated_at'] ?? '')) ?: 0;
            if (filemtime($cache) >= $tplTs) {
                return $cache;
            }
        }

        $pdf = $this->build($badge);
        if ($pdf === null) {
            return null;
        }

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0750, true);
        }
        if (file_put_contents($cache, $pdf) === false || !is_readable($cache)) {
            return null;
        }
        @chmod($cache, 0640);
        return $cache;
    }

    /**
     * Compone el certificado y devuelve los bytes del PDF (o null si falla).
     *
     * @param array<string,mixed> $badge
     */
    private function build(array $badge): ?string
    {
        $tplPath = self::TEMPLATE_DIR . basename((string) $badge['certificate_filename']);
        if (!is_file($tplPath)) {
            return null;
        }

        $info = @getimagesize($tplPath);
        if ($info === false) {
            return null;
        }
        $img = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($tplPath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tplPath),
            default        => false,
        };
        if (!$img instanceof \GdImage) {
            return null;
        }
        imagealphablending($img, true);

        $w   = imagesx($img);
        $h   = imagesy($img);
        $cfg = self::decodeConfig($badge['certificate_config'] ?? null);

        // --- Textos ---
        $values = [
            'name'    => trim((string) $badge['first_name'] . ' ' . (string) $badge['last_name']),
            'course'  => (string) $badge['template_name'],
            'cert_id' => (string) $badge['uuid'],
            'date'    => $this->formatDate((string) $badge['issued_at'], (string) ($cfg['date']['format'] ?? 'long_es')),
        ];

        foreach (self::TEXT_FIELDS as $field) {
            if (!isset($cfg[$field]) || $values[$field] === '') {
                continue;
            }
            $box      = $cfg[$field];
            $fontPath = Font::pathFor((int) ($box['font'] ?? 0))
                ?? BASE_PATH . '/lib/fonts/PublicSans-Regular.ttf';
            $this->drawText(
                $img,
                $values[$field],
                $fontPath,
                (int) round((float) $box['x'] * $w),
                (int) round((float) $box['y'] * $h),
                (int) round((float) $box['w'] * $w),
                (int) round((float) $box['h'] * $h),
                (string) ($box['color'] ?? '#1a2233'),
                (string) ($box['align'] ?? 'center')
            );
        }

        // --- QR ---
        if (isset($cfg['qr'])) {
            $verifyUrl = public_url('verify/' . (string) $badge['uuid']);
            $qrImg = QrCode::gd($verifyUrl, 6, 2);
            if ($qrImg instanceof \GdImage) {
                $side = (int) round((float) $cfg['qr']['size'] * $w);
                $qx   = (int) round((float) $cfg['qr']['x'] * $w);
                $qy   = (int) round((float) $cfg['qr']['y'] * $h);
                imagecopyresampled($img, $qrImg, $qx, $qy, 0, 0, $side, $side, imagesx($qrImg), imagesy($qrImg));
                imagedestroy($qrImg);
            }
        }

        // --- Imagen compuesta -> JPEG ---
        ob_start();
        imagejpeg($img, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        return $this->jpegToPdf($jpeg, $w, $h);
    }

    /**
     * Dibuja un texto centrado vertical y (según align) horizontalmente dentro
     * del box, auto-ajustando el tamaño de fuente para que entre.
     */
    private function drawText(\GdImage $img, string $text, string $fontPath, int $bx, int $by, int $bw, int $bh, string $hex, string $align): void
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $color = imagecolorallocate($img, $r, $g, $b);

        // Buscar el tamaño de fuente que entra en el box (alto y ancho).
        $size = max(6.0, $bh * 0.95);
        $bbox = [];
        for ($i = 0; $i < 40; $i++) {
            $bbox = imagettfbbox($size, 0, $fontPath, $text);
            $tw = abs($bbox[2] - $bbox[0]);
            $th = abs($bbox[1] - $bbox[7]);
            if ($tw <= $bw && $th <= $bh) {
                break;
            }
            $size -= max(1.0, $size * 0.07);
            if ($size < 6) {
                break;
            }
        }

        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[1] - $bbox[7];

        $x = match ($align) {
            'left'  => $bx - $bbox[0],
            'right' => $bx + $bw - $tw - $bbox[0],
            default => $bx + (int) (($bw - $tw) / 2) - $bbox[0],
        };
        $y = $by + (int) (($bh - $th) / 2) - $bbox[7]; // baseline

        imagettftext($img, $size, 0, $x, $y, $color, $fontPath, $text);
    }

    private function formatDate(string $datetime, string $format): string
    {
        $ts = strtotime($datetime) ?: time();
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return match ($format) {
            'short'   => date('d/m/Y', $ts),
            'long_en' => date('F j, Y', $ts),
            default   => (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts),
        };
    }

    /**
     * Envuelve un JPEG en un PDF de una sola página (a tamaño A4-ish preservando
     * la relación de aspecto). PDF mínimo escrito a mano (sin librerías).
     */
    private function jpegToPdf(string $jpeg, int $imgW, int $imgH): string
    {
        $scale = 842.0 / max($imgW, $imgH);          // lado mayor ~ A4 largo
        $pw = round($imgW * $scale, 2);
        $ph = round($imgH * $scale, 2);

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] "
                    . "/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>";
        $objects[4] = "<< /Type /XObject /Subtype /Image /Width {$imgW} /Height {$imgH} "
                    . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length "
                    . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
        $content   = "q {$pw} 0 0 {$ph} 0 0 cm /Im0 Do Q";
        $objects[5] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

        $pdf  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= 5; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n" . $objects[$i] . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeConfig(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (is_string($json) && $json !== '') {
            $d = json_decode($json, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [26, 34, 51];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
