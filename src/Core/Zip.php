<?php

declare(strict_types=1);

namespace HexBadge\Core;

/**
 * Generador de archivos ZIP en PHP puro (método "store", sin compresión).
 *
 * No depende de la extensión ext-zip (que no está garantizada en hosting
 * compartido). El contenido típico aquí son PDFs, que ya vienen comprimidos
 * (JPEG embebido), así que no compensa deflate. Escribe los tres bloques del
 * formato: local file headers, central directory y end-of-central-directory.
 */
final class Zip
{
    /**
     * Devuelve los bytes de un ZIP con los archivos dados (nombre => contenido).
     *
     * @param array<string,string> $files
     */
    public static function create(array $files): string
    {
        $local   = '';
        $central = '';
        $offset  = 0;
        $count   = 0;

        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', (string) $name);
            $crc  = crc32($data);
            $len  = strlen($data);

            // Local file header (firma PK\x03\x04) + datos crudos.
            $localHeader = "PK\x03\x04"
                . pack('v', 20)            // versión necesaria
                . pack('v', 0)             // flags
                . pack('v', 0)             // método: 0 = store
                . pack('v', 0) . pack('v', 0) // hora/fecha de modificación (0)
                . pack('V', $crc)
                . pack('V', $len)          // tamaño comprimido (= sin comprimir)
                . pack('V', $len)          // tamaño sin comprimir
                . pack('v', strlen($name))
                . pack('v', 0)             // extra field length
                . $name;
            $local .= $localHeader . $data;

            // Central directory record (firma PK\x01\x02).
            $central .= "PK\x01\x02"
                . pack('v', 20)            // versión que lo creó
                . pack('v', 20)            // versión necesaria
                . pack('v', 0) . pack('v', 0) // flags, método
                . pack('v', 0) . pack('v', 0) // hora/fecha
                . pack('V', $crc)
                . pack('V', $len) . pack('V', $len)
                . pack('v', strlen($name))
                . pack('v', 0) . pack('v', 0) // extra, comment length
                . pack('v', 0) . pack('v', 0) // disk number, internal attrs
                . pack('V', 0)             // external attrs
                . pack('V', $offset)       // offset del local header
                . $name;

            $offset += strlen($localHeader) + $len;
            $count++;
        }

        // End of central directory.
        $eocd = "PK\x05\x06"
            . pack('v', 0) . pack('v', 0)  // disk numbers
            . pack('v', $count) . pack('v', $count)
            . pack('V', strlen($central))
            . pack('V', strlen($local))    // offset del central directory
            . pack('v', 0);                // comment length

        return $local . $central . $eocd;
    }
}
