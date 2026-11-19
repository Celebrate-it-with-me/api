<?php

namespace App\Exports\Catering;

use App\Exports\Roster\SafeCells;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One sheet of the catering list: a title, the headings and ready rows. Every string is written as text (SafeCells). */
class CateringSheet implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    use SafeCells;

    /** @param  list<string>  $headings  @param  list<list<string|int|null>>  $rows */
    public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows) {}

    public function title(): string
    {
        // Excel limits a sheet name to 31 characters and forbids some of them.
        return mb_substr(preg_replace('#[\\\\/*?:\[\]]#', ' ', $this->title), 0, 31);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return list<list<string|int|null>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FCE7F3']]]];
    }
}
