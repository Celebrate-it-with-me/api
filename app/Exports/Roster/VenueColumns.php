<?php

namespace App\Exports\Roster;

use App\Models\Guest;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** What the two sheets of the venue list share: labels, the menu text, the header style and the total line. */
trait VenueColumns
{
    private function t(string $key): string
    {
        return (string) __("exports.venue.$key", [], $this->locale);
    }

    /** Starter, main and dessert chosen by the guest, in that order. */
    private function menuOf(Guest $guest): string
    {
        $order = ['starter' => 1, 'main' => 2, 'dessert' => 3];

        return $guest->selectedMenuItems
            ->sortBy(fn ($item) => $order[$item->type] ?? 4)
            ->pluck('name')
            ->implode(' / ');
    }

    /** @return array<int, mixed> */
    private function headerStyle(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FCE7F3']]]];
    }

    private function total(Worksheet $sheet, int $people): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", $this->t('total'));
        $sheet->setCellValue("B{$row}", $people);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
    }
}
