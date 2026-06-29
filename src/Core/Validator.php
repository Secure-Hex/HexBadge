<?php

declare(strict_types=1);

namespace HexBadge\Core;

use InvalidArgumentException;

/**
 * Validación y sanitización de inputs (CLAUDE.md §4.8).
 *
 * Cada método lanza InvalidArgumentException con un mensaje apto para
 * mostrar al usuario (sin detalles internos). Nunca confiar en el cliente.
 */
final class Validator
{
    public function email(string $input): string
    {
        $clean = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        if ($clean === false) {
            throw new InvalidArgumentException('Email inválido');
        }
        if (strlen($clean) > 255) {
            throw new InvalidArgumentException('Email demasiado largo');
        }
        return strtolower($clean);
    }

    public function name(string $input, int $max = 100): string
    {
        $clean = trim(strip_tags($input));
        if ($clean === '') {
            throw new InvalidArgumentException('Campo requerido');
        }
        if (mb_strlen($clean) > $max) {
            throw new InvalidArgumentException("Máximo {$max} caracteres");
        }
        return $clean;
    }

    public function text(string $input, int $max = 5000, bool $required = true): string
    {
        $clean = trim($input);
        if ($required && $clean === '') {
            throw new InvalidArgumentException('Campo requerido');
        }
        if (mb_strlen($clean) > $max) {
            throw new InvalidArgumentException("Máximo {$max} caracteres");
        }
        return $clean;
    }

    public function uuid(string $input): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $input)) {
            throw new InvalidArgumentException('UUID inválido');
        }
        return strtolower($input);
    }

    public function url(string $input, bool $required = false): ?string
    {
        $clean = trim($input);
        if ($clean === '') {
            if ($required) {
                throw new InvalidArgumentException('URL requerida');
            }
            return null;
        }
        $valid = filter_var($clean, FILTER_VALIDATE_URL);
        if ($valid === false || !preg_match('#^https?://#i', $clean)) {
            throw new InvalidArgumentException('URL inválida');
        }
        if (strlen($clean) > 500) {
            throw new InvalidArgumentException('URL demasiado larga');
        }
        return $clean;
    }

    public function int(string $input, int $min = 0, ?int $max = null, bool $required = true): ?int
    {
        $clean = trim($input);
        if ($clean === '') {
            if ($required) {
                throw new InvalidArgumentException('Campo numérico requerido');
            }
            return null;
        }
        if (!preg_match('/^-?\d+$/', $clean)) {
            throw new InvalidArgumentException('Debe ser un número entero');
        }
        $value = (int) $clean;
        if ($value < $min || ($max !== null && $value > $max)) {
            throw new InvalidArgumentException('Valor fuera de rango');
        }
        return $value;
    }

    public function password(string $input): string
    {
        // Política mínima: 12+ caracteres. No truncar; bcrypt admite hasta 72 bytes.
        if (strlen($input) < 12) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres');
        }
        if (strlen($input) > 200) {
            throw new InvalidArgumentException('Contraseña demasiado larga');
        }
        return $input;
    }

    public function inList(string $input, array $allowed, string $field = 'valor'): string
    {
        $clean = trim($input);
        if (!in_array($clean, $allowed, true)) {
            throw new InvalidArgumentException("El {$field} no es válido");
        }
        return $clean;
    }

    public function locale(string $input): string
    {
        return $this->inList(strtolower(trim($input)), ['es', 'en'], 'idioma');
    }
}
