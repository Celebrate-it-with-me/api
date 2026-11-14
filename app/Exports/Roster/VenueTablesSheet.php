<?php

namespace App\Exports\Roster;

use App\Models\Events;
use App\Models\Seating\TableAssignment;
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

/** The venue list by table: people seated at each table, in seat order. */
class VenueTablesSheet implements FromQuery, ShouldAutoSize, WithCustomChunkSize, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    use SafeCells;
    use VenueColumns;

    private int $row = 0;

    public function __construct(
        private readonly Events $event,
        private readonly bool $everyone,
        private readonly string $locale,
        private readonly bool $menus,
    ) {}

    public function query(): Builder
    {
        $with = ['table:id,name', 'guest:id,name,parent_id,rsvp_status,allergies,notes', 'guest.parent:id,name'];
        if ($this->menus) {
            $with[] = 'guest.selectedMenuItems:menu_items.id,menu_items.name,menu_items.type';
        }

        return TableAssignment::query()
            ->with($with)
            ->select('table_assignments.*')
            ->join('tables', 'tables.id', '=', 'table_assignments.table_id')
            ->join('guests', 'guests.id', '=', 'table_assignments.guest_id')
            ->where('tables.event_id', $this->event->id)
            ->whereNull('guests.deleted_at')
            ->when(! $this->everyone, fn (Builder $q) => $q->where('guests.rsvp_status', 'attending'))
            ->orderBy('tables.name')
            ->orderBy('tables.id')
            ->orderByRaw("LPAD(table_assignments.seat_number, 6, '0')")
            ->orderBy('table_assignments.id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return $this->t('sheet_tables');
    }

    /** @return list<string> */
    public function headings(): array
    {
        $columns = ['table', 'seat', 'name', 'family'];
        if ($this->menus) {
            array_push($columns, 'menu', 'restrictions');
        }
        $columns[] = 'notes';

        return array_map($this->t(...), $columns);
    }

    /** @param  TableAssignment  $assignment  @return list<string|int|null> */
    public function map($assignment): array
    {
        $guest = $assignment->guest;
        $this->row++;
        $row = [$assignment->table->name, $assignment->seat_number, $guest->name, $guest->parent?->name ?? $guest->name];

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
