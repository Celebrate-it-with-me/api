<?php

namespace App\Exports\Roster;

use App\Http\Services\AppServices\EventModules;
use App\Models\Events;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The list for the venue: every person (main guests and companions), alphabetical, and, when the Tables module is
 * active for the event, a second sheet by table. The menu and dietary columns exist only when the Menu module is active.
 * Pending and declined people are left out unless `$everyone` is set: the venue cooks and seats the confirmed ones.
 */
class VenueExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private readonly Events $event,
        private readonly bool $everyone,
        private readonly string $locale,
    ) {}

    /** @return list<object> */
    public function sheets(): array
    {
        $modules = app(EventModules::class);
        $menus = $modules->isActive($this->event, 'menus');
        $seating = $modules->isActive($this->event, 'seating');

        $sheets = [new VenuePeopleSheet($this->event, $this->everyone, $this->locale, $menus, $seating)];

        if ($seating) {
            $sheets[] = new VenueTablesSheet($this->event, $this->everyone, $this->locale, $menus);
        }

        return $sheets;
    }
}
