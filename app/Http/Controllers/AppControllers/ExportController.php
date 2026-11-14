<?php

namespace App\Http\Controllers\AppControllers;

use App\Exports\GuestMenuExport;
use App\Http\Controllers\Controller;
use App\Models\Events;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function exportGuestMenuSelections(Events $event)
    {
        $filename = "guest_menu_selections_event_{$event->id}.xlsx";

        return Excel::download(
            new GuestMenuExport($event),
            $filename
        );
    }
}
