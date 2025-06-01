<?php

namespace App\Http\Controllers\AppControllers;

use App\Exports\GuestMenuExport;
use App\Exports\RSVPExport;
use App\Http\Controllers\Controller;
use App\Models\Events;
use App\Models\Guest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function handleExportRequest(Request $request, Events $event)
    {
        $type = $request->input('exportType');
        $status = $request->query('status');
        $search = $request->query('searchValue') ?? '';
        $perPage = $request->query('perPage', 15);
        $currentPage = $request->query('currentPage', 1);

        if (! in_array($type, ['excel', 'pdf'])) {
            return response()->json(['message' => 'Invalid export type'], 422);
        }

        $filename = "rsvp_export_{$event->id}." . ($type === 'excel' ? 'xlsx' : 'pdf');

        if ($type === 'excel') {
            return Excel::download(
                new RsvpExport($event->id, $status, $search, $perPage, $currentPage),
                $filename
            );
        }

        $pdf = PDF::loadView('pdf.rsvp', [
            'event' => $event,
            'guests' => $this->getFilteredGuests($event, $status, $search, $perPage, $currentPage),
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);

    }

    public function exportGuestMenuSelections(Events $event)
    {
        $filename = "guest_menu_selections_event_{$event->id}.xlsx";

        return Excel::download(
            new GuestMenuExport($event),
            $filename
        );
    }

    private function getFilteredGuests(Events $event, $status = null, $search = null, $perPage = 15, $currentPage = 1): Collection
    {
        return Guest::query()
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->with('companions')
            ->when($status, fn ($q) => $q->where('rsvp_status', $status))
            ->when(! empty($search), function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('companions', function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name')
            ->forPage($currentPage, $perPage)
            ->get();
    }
}
