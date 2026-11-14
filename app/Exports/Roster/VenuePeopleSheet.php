<?php

namespace App\Exports\Roster;

use App\Models\Events;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Alphabetical sheet of the venue list: one row per person. */
class VenuePeopleSheet implements FromQuery, ShouldAutoSize, WithCustomChunkSize, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    use SafeCells;
    use VenueColumns;

    private int $row = 0;

    public function __construct(
        private readonly Events $event,
        private readonly bool $everyone,
        private readonly string $locale,
        private readonly bool $menus,
        private readonly bool $seating,
    ) {}

    public function query(): Builder
    {
        $with = ['parent:id,name'];
        if ($this->menus) {
            $with[] = 'selectedMenuItems:menu_items.id,menu_items.name,menu_items.type';
        }
        if ($this->seating) {
            $with[] = 'tableAssignment.table:id,name';
        }

        return Guest::query()
            ->with($with)
            ->where('event_id', $this->event->id)
            ->when(! $this->everyone, fn (Builder $q) => $q->where('rsvp_status', 'attending'))
            ->orderBy('name')
            ->orderBy('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return $this->t('sheet_alphabetical');
    }

    /** @return list<string> */
    public function headings(): array
    {
        $columns = ['number', 'name', 'family'];
        if ($this->seating) {
            array_push($columns, 'table', 'seat');
        }
        if ($this->menus) {
            array_push($columns, 'menu', 'restrictions');
        }
        $columns[] = 'notes';

        return array_map($this->t(...), $columns);
    }

    /** @param  Guest  $guest  @return list<string|int|null> */
    public function map($guest): array
    {
        $assignment = $this->seating ? $guest->tableAssignment : null;
        $row = [++$this->row, $guest->name, $guest->parent?->name ?? $guest->name];

        if ($this->seating) {
            array_push($row, $assignment?->table?->name ?? $this->t('unassigned'), $assignment?->seat_number);
        }
        if ($this->menus) {
            array_push($row, $this->menuOf($guest), $guest->allergies);
        }
        $row[] = $guest->notes;

        return $row;
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $sheet): array
    {
        return $this->headerStyle($sheet);
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->total($event->sheet->getDelegate(), $this->row)];
    }
}
