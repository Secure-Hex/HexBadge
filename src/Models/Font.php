<?php

declare(strict_types=1);

namespace HexBadge\Models;

final class Font extends Model
{
    protected static string $table = 'fonts';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function allOrdered(): array
    {
        return static::db()->fetchAll(
            'SELECT * FROM fonts ORDER BY is_builtin DESC, name ASC'
        );
    }

    /**
     * Ruta absoluta del archivo de la fuente, o null si no existe.
     */
    public static function pathFor(int $id): ?string
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        $abs = BASE_PATH . '/' . ltrim((string) $row['file_path'], '/');
        return is_file($abs) ? $abs : null;
    }
}
