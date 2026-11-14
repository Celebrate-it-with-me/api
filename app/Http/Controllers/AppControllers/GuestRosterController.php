<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\RosterGuestRequest;
use App\Http\Services\AppServices\GuestLimit;
use App\Http\Services\AppServices\Roster\GuestRoster;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The unified "Invitados" screen: list with status tabs and counters, plus editing people. */
class GuestRosterController extends Controller
{
    public function __construct(
        private readonly GuestRoster $roster,
        private readonly GuestLimit $limit,
    ) {}

    public function index(Request $request, Events $event): JsonResponse
    {
        $page = $this->roster->page(
            $event,
            $request->query('status'),
            $request->query('search'),
            (int) $request->query('perPage', 15),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (Guest $guest) => $this->roster->row($event, $guest))->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
            'counts' => $this->roster->counts($event),
            'eventLocale' => $event->locale ?: 'es',
        ]);
    }

    /** Set the language of one family's invitation: es, en, or null to follow the event. */
    public function updateLocale(Request $request, Events $event, Guest $guest): JsonResponse
    {
        abort_unless($guest->parent_id === null, 422, 'The invitation language belongs to a main guest.');

        $data = $request->validate(['locale' => ['present', 'nullable', 'string', 'in:es,en']]);
        $guest->update(['locale' => $data['locale']]);

        return response()->json(['data' => $this->roster->row($event, $guest->load('companions'))]);
    }

    public function update(RosterGuestRequest $request, Events $event, Guest $guest): JsonResponse
    {
        $guest->update($request->person());

        return response()->json(['data' => $this->roster->row($event, $this->main($guest)->load('companions'))]);
    }

    /** Add a companion to a main guest. Counts against the plan limit once the event is published. */
    public function storeCompanion(RosterGuestRequest $request, Events $event, Guest $guest): JsonResponse
    {
        abort_unless($guest->parent_id === null, 422, 'Companions belong to a main guest.');

        $this->limit->assertCanAdd($event, 1);

        Guest::query()->create([
            ...$request->person(),
            'event_id' => $event->id,
            'parent_id' => $guest->id,
            'rsvp_status' => 'pending',
        ]);
        $this->roster->forgetStats($event);

        return response()->json(['data' => $this->roster->row($event, $guest->load('companions'))], 201);
    }

    /** Remove a person. Removing a main guest removes their companions too. */
    public function destroy(Events $event, Guest $guest): JsonResponse
    {
        $main = $this->main($guest);
        EventActivityLogger::log($event->id, 'guest_deleted', Auth::user(), $guest, ['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone, 'code' => $guest->code]);
        if ($guest->parent_id === null) {
            $guest->companions()->delete();
        }
        $guest->delete();
        $this->roster->forgetStats($event);

        return response()->json([
            'data' => $guest->parent_id === null ? null : $this->roster->row($event, $main->load('companions')),
        ]);
    }

    private function main(Guest $guest): Guest
    {
        return $guest->parent_id ? Guest::query()->findOrFail($guest->parent_id) : $guest;
    }
}
