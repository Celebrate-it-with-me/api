<?php

namespace App\Support\TemplatePackage;

/**
 * Structural validation of a WOFF2 file (W3C WOFF2 spec): header, table directory (UIntBase128 lengths), the compressed
 * stream where the header says it is, and nothing else after it. The Brotli stream itself is not decompressed (no
 * decoder in the runtime); the browser's sanitizer is the last line, behind the fixed Content-Type and nosniff.
 */
final class Woff2Validator
{
    private const KNOWN_TAGS = [
        'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG',
        'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC',
        'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc',
        'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat',
        'Gloc', 'Feat', 'Sill',
    ];

    private const MAX_SFNT = 16_777_216;

    /** @return string|null the reason it is not a well-formed woff2, null when it is */
    public static function problem(string $b): ?string
    {
        $n = strlen($b);
        if ($n < 48 || ! str_starts_with($b, 'wOF2')) {
            return 'No es un woff2 (firma wOF2 o tamaño).';
        }
        $h = unpack('Nsig/Nflavor/Nlength/nnumTables/nreserved/NtotalSfnt/NtotalCompressed/nmajor/nminor/NmetaOffset/NmetaLength/NmetaOrig/NprivOffset/NprivLength', $b);
        if ($h['length'] !== $n) {
            return 'La longitud de la cabecera no coincide con el tamaño real.';
        }
        if ($h['reserved'] !== 0 || $h['numTables'] < 1 || $h['numTables'] > 64) {
            return 'Cabecera inválida (reservado o número de tablas).';
        }
        if (! in_array($h['flavor'], [0x00010000, 0x4F54544F, 0x74727565], true)) {
            return 'Tipo de fuente (flavor) desconocido.';
        }
        if ($h['totalSfnt'] < 12 + 16 * $h['numTables'] || $h['totalSfnt'] > self::MAX_SFNT) {
            return 'Tamaño sfnt incoherente.';
        }
        if ($h['metaOffset'] !== 0 || $h['metaLength'] !== 0 || $h['metaOrig'] !== 0 || $h['privOffset'] !== 0 || $h['privLength'] !== 0) {
            return 'Bloques de metadatos o privados no permitidos.';
        }

        $pos = 48;
        $orig = 0;
        for ($i = 0; $i < $h['numTables']; $i++) {
            if ($pos >= $n) {
                return 'Tabla de directorio truncada.';
            }
            $flags = ord($b[$pos++]);
            $index = $flags & 0x3F;
            $version = $flags >> 6;
            if ($index === 63) {
                if ($pos + 4 > $n || ! preg_match('/^[\x20-\x7E]{4}$/', substr($b, $pos, 4))) {
                    return 'Etiqueta de tabla inválida.';
                }
                $tag = substr($b, $pos, 4);
                $pos += 4;
            } else {
                $tag = self::KNOWN_TAGS[$index];
            }
            $len = self::base128($b, $pos);
            if ($len === null) {
                return 'Longitud de tabla inválida (UIntBase128).';
            }
            $orig += $len;
            $transformed = in_array($tag, ['glyf', 'loca'], true) ? $version === 0 : $version !== 0;
            if ($transformed && self::base128($b, $pos) === null) {
                return 'Longitud transformada inválida (UIntBase128).';
            }
        }
        if ($orig > self::MAX_SFNT) {
            return 'Tablas desmesuradas.';
        }
        if ($h['totalCompressed'] < 1 || $pos + $h['totalCompressed'] > $n) {
            return 'El bloque comprimido no cabe en el archivo.';
        }
        $end = $pos + $h['totalCompressed'];
        $padded = ($end + 3) & ~3;
        if ($n !== $padded || strspn(substr($b, $end), "\0") !== $n - $end) {
            return 'Hay bytes después del bloque comprimido.';
        }

        return null;
    }

    private static function base128(string $b, int &$pos): ?int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            if (! isset($b[$pos])) {
                return null;
            }
            $byte = ord($b[$pos++]);
            if ($i === 0 && $byte === 0x80) {
                return null;
            }
            if ($value & 0xFE000000) {
                return null;
            }
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }
}
