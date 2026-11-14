<?php

namespace App\Exports\Roster;

use App\Http\Services\AppServices\Roster\GuestRoster;
use App\Models\Events;
use App\Models\Guest;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The guest list of the event for the organizer: one row per family (main guest), with the companions' names and the
 * people count. Same tab and search as the "Invitados" screen. Read in chunks of 500 families, with the companions
 * loaded per chunk, so a list of thousands costs a handful of queries and little memory.
 */
class RosterListExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomChunkSize, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    use Exportable;
    use SafeCells;

    private int $people = 0;

    public function __construct(
        private readonly Events $event,
        private readonly ?string $status,
        private readonly ?string $search,
        private readonly string $locale,
    ) {}

    public function query(): Builder
    {
        return app(GuestRoster::class)->query($this->event, $this->status, $this->search);
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return $this->t('sheet');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map($this->t(...), ['name', 'companions', 'people', 'attending', 'phone', 'email', 'status', 'language', 'notes']);
    }

    /** @param  Guest  $guest  @return list<string|int|null> */
    public function map($guest): array
    {
        $people = collect([$guest, ...$guest->companions->all()]);
        $this->people += $people->count();
        $effective = $guest->locale ?: ($this->event->locale ?: 'es');

        return [
            $guest->name,
            $guest->companions->pluck('name')->implode('; '),
            $people->count(),
            $people->where('rsvp_status', 'attending')->count(),
            $this->phone($guest->phone),
            $guest->email,
            $this->label('status', $guest->rsvp_status),
            $this->label('locale', $effective),
            $guest->notes,
        ];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return ['E' => NumberFormat::FORMAT_TEXT];
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FCE7F3']]]];
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $row = $sheet->getHighestRow() + 2;
                $sheet->setCellValue("A{$row}", $this->t('total'));
                $sheet->setCellValue("C{$row}", $this->people);
                $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
            },
        ];
    }

    /** The phone in E.164 when it can be read as one; as typed otherwise. */
    private function phone(?string $raw): ?string
    {
        return PhoneNumber::normalize($raw)['e164'] ?? ($raw !== null && trim($raw) !== '' ? trim($raw) : null);
    }

    private function label(string $group, ?string $key): string
    {
        return $key ? (string) __("exports.$group.$key", [], $this->locale) : '';
    }

    private function t(string $key): string
    {
        return (string) __("exports.roster.$key", [], $this->locale);
    }
}
