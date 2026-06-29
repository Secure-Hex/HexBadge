<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Tokens CSRF (CLAUDE.md §4.1).
 *
 * Token generado con random_bytes(32), almacenado en sesión y verificado
 * con hash_equals (comparación en tiempo constante) antes de procesar
 * cualquier POST.
 */
final class CSRF
{
    private const SESSION_KEY = '_csrf_token';

    /**
     * Devuelve el token de la sesión, generándolo si no existe.
     */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    /**
     * Campo oculto listo para insertar en formularios.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    /**
     * Verifica el token recibido contra el de la sesión.
     */
    public static function verify(?string $token): bool
    {
        $stored = Session::get(self::SESSION_KEY);
        if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Verifica el token desde una petición POST; lanza si es inválido.
     */
    public static function check(Request $request): void
    {
        if (!self::verify($request->input('_csrf'))) {
            $response = Response::html('<h1>419 — Token CSRF inválido o expirado</h1>', 419);
            $response->send();
            exit;
        }
    }
}
