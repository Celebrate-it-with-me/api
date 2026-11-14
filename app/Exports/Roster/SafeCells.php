<?php

namespace App\Exports\Roster;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Guest names and notes are typed by people (or imported from a file), so a cell must never become a formula.
 *
 * Every string is written as text: nothing is evaluated, a name like "1/2" is not turned into a date and a phone
 * like "+13055550101" stays as typed. Text that starts with `=`, `+`, `-`, `@`, tab or CR (the characters spreadsheet
 * programs read as the start of a formula) also gets the "quote prefix" style, which is Excel's own apostrophe: the
 * cell shows the text as is, and editing it keeps it as text.
 */
trait SafeCells
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
                $cell->getStyle()->setQuotePrefix(true);
            }

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }
}
