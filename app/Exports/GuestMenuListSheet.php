<?php

namespace App\Exports;

use App\Exports\Roster\SafeCells;
use App\Models\Events;
use App\Models\Guest;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GuestMenuListSheet implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStyles, WithTitle
{
    // Guest names are typed by guests: no cell becomes a formula (I-V5-6).
    use SafeCells;

    private Events $event;

    public function __construct(Events $event)
    {
        $this->event = $event;
    }

    public function array(): array
    {
        $guests = Guest::query()
            ->with('selectedMenuItems')
            ->where('event_id', $this->event->id)
            ->orderBy('name')
            ->get();

        return $guests->map(function ($guest) {
            return [
                'Name' => $guest->name,
                'Email' => $guest->email,
                'Starter' => $guest->selectedMenuItems->firstWhere('type', 'starter')?->name ?? '',
                'Main Course' => $guest->selectedMenuItems->firstWhere('type', 'main')?->name ?? '',
                'Dessert' => $guest->selectedMenuItems->firstWhere('type', 'dessert')?->name ?? '',
            ];
        })->toArray();
    }

    public function title(): string
    {
        return 'Guests';
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'Starter', 'Main Course', 'Dessert'];
    }

    /**
     * @throws Exception
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '000000'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FCE7F3'], // soft pink
            ],
        ]);

        return [];
    }
}
