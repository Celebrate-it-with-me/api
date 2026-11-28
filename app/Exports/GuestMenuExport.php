<?php

namespace App\Exports;

use App\Models\Events;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** The menu choices of an event for the caterer: the list per guest and a summary by menu and dish. */
class GuestMenuExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(private readonly Events $event) {}

    public function sheets(): array
    {
        return [new GuestMenuListSheet($this->event), new GuestMenuSummarySheet($this->event)];
    }
}
