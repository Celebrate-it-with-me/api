<?php

namespace App\Exports;

use App\Exports\Roster\SafeCells;
use App\Http\Services\AppServices\Menu\MenuSelectionSummary;
use App\Models\Events;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The caterer's sheet: how many people chose each menu and each dish, over all the guests of the event. */
class GuestMenuSummarySheet implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    use SafeCells;

    private const COURSE_LABELS = ['starter' => 'Starter', 'main' => 'Main Course', 'dessert' => 'Dessert'];

    public function __construct(private readonly Events $event) {}

    public function title(): string
    {
        return 'Summary';
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        $s = app(MenuSelectionSummary::class)->for($this->event);

        $rows = [['Guests', 'Chose', 'Pending'], [$s['total'], $s['responded'], $s['pending']], [], ['Menu', 'Guests']];

        foreach ($s['menus'] as $menu) {
            $rows[] = [$menu['name'], $menu['count']];
        }

        $rows[] = [];
        $rows[] = ['Course', 'Dish', 'Guests'];

        foreach ($s['courses'] as $type => $dishes) {
            foreach ($dishes as $dish) {
                $rows[] = [self::COURSE_LABELS[$type], $dish['name'], $dish['count']];
            }
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        foreach ($sheet->getRowIterator() as $row) {
            $first = $sheet->getCell('A' . $row->getRowIndex())->getValue();

            if (in_array($first, ['Guests', 'Menu', 'Course'], true) && ! is_int($first)) {
                $sheet->getStyle("A{$row->getRowIndex()}:C{$row->getRowIndex()}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE7F3']],
                ]);
            }
        }

        return [];
    }
}
