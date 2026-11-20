<?php

namespace App\Support;

/**
 * CSV files built from text people typed (song titles, vendor names, item titles) must not carry formulas.
 *
 * A text cell that starts with `=`, `+`, `-`, `@`, tab or CR is read by Excel and Sheets as a formula, so it gets a
 * leading apostrophe (the OWASP remedy): the cell shows the text and nothing runs. Only strings are touched, numbers
 * (including negative ones) stay numbers. The XLSX equivalent is `App\Exports\Roster\SafeCells`.
 */
class CsvCells
{
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
