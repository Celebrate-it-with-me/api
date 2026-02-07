<?php

namespace App\Exports;

use App\Models\Events;
use App\Models\Guest;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GuestMenuExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize, WithEvents
{
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
    
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $startRow = $lastRow + 4;
                
                $guests = Guest::query()
                    ->with('selectedMenuItems')
                    ->where('event_id', $this->event->id)
                    ->get();
                
                $flatItems = [];
                
                foreach ($guests as $guest) {
                    foreach ($guest->selectedMenuItems as $item) {
                        $flatItems[] = [
                            'type' => ucfirst($item->type),
                            'name' => $item->name,
                        ];
                    }
                }
                
                $grouped = collect($flatItems)
                    ->groupBy(fn($i) => "{$i['type']}|{$i['name']}")
                    ->map(fn($group, $key) => [
                        'type' => explode('|', $key)[0],
                        'name' => explode('|', $key)[1],
                        'count' => $group->count(),
                    ])
                    ->sortBy(function ($item) {
                        return match ($item['type']) {
                            'Starter' => 1,
                            'Main' => 2,
                            'Dessert' => 3,
                            default => 4,
                        };
                    })
                    ->values();
                
                $sheet->setCellValue("A" . ($startRow - 1), 'Total by Dish');
                $sheet->getStyle("A" . ($startRow - 1))->applyFromArray([
                    'font' => [
                        'bold' => false,
                        'size' => 14,
                        'color' => ['rgb' => '000000'],
                    ],
                ]);
                
                $sheet->setCellValue("A{$startRow}", 'Dish Type');
                $sheet->setCellValue("B{$startRow}", 'Dish Name');
                $sheet->setCellValue("C{$startRow}", 'Total Selected');
                
                $sheet->getStyle("A{$startRow}:C{$startRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F3E8FF'],
                    ],
                ]);
                
                foreach ($grouped as $index => $row) {
                    $r = $startRow + 1 + $index;
                    $sheet->setCellValue("A{$r}", $row['type']);
                    $sheet->setCellValue("B{$r}", $row['name']);
                    $sheet->setCellValue("C{$r}", $row['count']);
                }
            },
        ];
    }
    
    
}
