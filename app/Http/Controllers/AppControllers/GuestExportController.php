<?php

namespace App\Http\Controllers\AppControllers;

use App\Exports\Roster\RosterListExport;
use App\Exports\Roster\VenueExport;
use App\Http\Controllers\Controller;
use App\Models\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel downloads of the guest list (the `guests` table). The route group gives `view_event`, so an event's
 * viewers can download; a user who is not a member gets 404.
 */
class GuestExportController extends Controller
{
    /**
     * The organizer's list: one row per family. `status` (attending, pending, not-attending; absent or "all" = everybody)
     * and `search` are the ones of the Invitados screen; `locale` (es, en) is the language of the headings.
     */
    public function roster(Request $request, Events $event): BinaryFileResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:all,attending,pending,not-attending'],
            'search' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'in:es,en'],
        ]);

        $status = ($data['status'] ?? 'all') === 'all' ? null : $data['status'];
        $locale = $data['locale'] ?? ($event->locale ?: 'es');

        return Excel::download(
            new RosterListExport($event, $status, $data['search'] ?? null, $locale),
            $this->filename($event, $locale === 'en' ? 'guests' : 'invitados'),
        );
    }

    /**
     * The venue's list: every person, alphabetical, plus a by-table sheet when the Tables module is active. Menu and
     * dietary columns appear only if the Menu module is. `scope` is attending (default) or all.
     */
    public function venue(Request $request, Events $event): BinaryFileResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:attending,all'],
            'locale' => ['nullable', 'string', 'in:es,en'],
        ]);

        $locale = $data['locale'] ?? ($event->locale ?: 'es');

        return Excel::download(
            new VenueExport($event, ($data['scope'] ?? 'attending') === 'all', $locale),
            $this->filename($event, $locale === 'en' ? 'venue' : 'salon'),
        );
    }

    /** `invitados-xv-de-valentina-2026-11-13.xlsx` */
    private function filename(Events $event, string $kind): string
    {
        $name = Str::slug($event->custom_url_slug ?: (string) $event->event_name) ?: 'event-' . $event->id;

        return "{$kind}-{$name}-" . now()->format('Y-m-d') . '.xlsx';
    }
}
