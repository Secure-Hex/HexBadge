<?php

declare(strict_types=1);

namespace HexBadge\Services;

/**
 * Plantilla de email con la identidad de SecureHex / HexBadge.
 *
 * HTML "email-safe": layout con tablas + estilos inline + fuentes del
 * sistema (los clientes de correo no cargan CSS externo ni web fonts, y
 * borran el SVG inline). El logo va como imagen hospedada en el dominio
 * público.
 */
final class EmailTemplate
{
    private const BLUE   = '#1565d8';
    private const TEXT   = '#0f1b2e';
    private const MUTED  = '#697587';
    private const BG     = '#f4f6fb';
    private const CARD   = '#ffffff';
    private const BORDER = '#e4e9f2';
    private const FONT   = "-apple-system,Segoe UI,Roboto,Arial,Helvetica,sans-serif";

    /**
     * Envuelve el contenido en el marco de marca (logo + footer).
     */
    public static function wrap(string $bodyHtml, string $preheader = ''): string
    {
        $logo   = self::logoUrl();
        $site   = 'https://securehex.cl';
        $f      = self::FONT;

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"></head>'
            . '<body style="margin:0;padding:0;background:' . self::BG . ';">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BG . ';padding:28px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:' . self::CARD . ';border:1px solid ' . self::BORDER . ';border-radius:16px;overflow:hidden">'
            // Header con logo
            . '<tr><td align="center" style="padding:28px 32px 8px">'
            . '<img src="' . e($logo) . '" alt="SecureHex" width="116" style="display:block;width:116px;height:auto;border:0">'
            . '</td></tr>'
            // Cuerpo
            . '<tr><td style="padding:8px 36px 8px;font-family:' . $f . ';color:' . self::TEXT . ';font-size:15px;line-height:1.6">'
            . $bodyHtml
            . '</td></tr>'
            // Footer
            . '<tr><td style="padding:24px 36px 30px">'
            . '<hr style="border:none;border-top:1px solid ' . self::BORDER . ';margin:0 0 16px">'
            . '<p style="margin:0;font-family:' . $f . ';font-size:12px;color:' . self::MUTED . ';text-align:center">'
            . '<strong style="color:' . self::TEXT . '">HexBadge</strong> — una herramienta de '
            . '<a href="' . $site . '" style="color:' . self::BLUE . ';text-decoration:none">SecureHex</a><br>'
            . '<span style="color:' . self::MUTED . '">securehex.cl</span></p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Botón "a prueba de balas" (compatible con la mayoría de clientes).
     */
    public static function button(string $text, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px auto"><tr>'
            . '<td align="center" style="border-radius:8px;background:' . self::BLUE . '">'
            . '<a href="' . e($url) . '" target="_blank" style="display:inline-block;padding:13px 30px;'
            . 'font-family:' . self::FONT . ';font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px">'
            . e($text) . '</a></td></tr></table>';
    }

    public static function badgeImage(string $url, string $alt = ''): string
    {
        return '<div style="text-align:center;margin:8px 0 4px">'
            . '<img src="' . e($url) . '" alt="' . e($alt) . '" width="150" '
            . 'style="display:inline-block;width:150px;height:auto;border:0;border-radius:10px"></div>';
    }

    public static function heading(string $text): string
    {
        return '<h1 style="margin:8px 0 12px;font-family:' . self::FONT . ';font-size:22px;font-weight:800;color:' . self::TEXT . ';text-align:center;letter-spacing:-.01em">' . e($text) . '</h1>';
    }

    public static function muted(string $text): string
    {
        return '<p style="margin:8px 0;font-family:' . self::FONT . ';font-size:13px;color:' . self::MUTED . ';text-align:center">' . $text . '</p>';
    }

    private static function logoUrl(): string
    {
        return public_url('assets/img/securehex-email-logo.png');
    }
}
