<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RsvpServices
{
    const STATUS_ATTENDING = 'attending';
    const STATUS_PENDING = 'pending';
    const STATUS_NOT_ATTENDING = 'not-attending';

    private Request $request;
    private Rsvp $rsvp;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->rsvp = new Rsvp();
    }

    public function create(Events $event)
    {
        return Rsvp::query()->create([
           'event_id' => $event->id,
           'title' => $this->request->input('title'),
           'description' => $this->request->input('description'),
           'custom_fields' => $this->request->input('customFields'),
           'confirmation_deadline' => $this->request->input('confirmationDeadline'),
        ]);
    }


    /**
     * Save rsvp info
     * @throws \Exception
     */
    public function saveRsvp(): bool
    {
        try {
            $requestGuest = $this->request->input('guest');

            $mainGuest = Guest::query()->findOrFail($requestGuest['id']);
            $this->saveGuest($requestGuest, $mainGuest);

            if (isset($requestGuest['companions'])) {
                foreach ($requestGuest['companions'] as $companion) {
                    $guestCompanion = Guest::query()->findOrFail($companion['id']);

                    $this->saveGuest($companion, $guestCompanion);
                }
            }

            Cache::forget("event.{$mainGuest->event_id}.rsvp.stats");

            return true;
        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }
    }

    /**
     * @param mixed $companion
     * @param Model|Collection|GuestCompanion|null $guestCompanion
     * @return void
     */
    private function saveGuest(array $guestData, Guest $guest): void {
        $guest->name = $guestData['name'];
        $guest->email = $guestData['email'];
        $guest->phone = $guestData['phone'];
        $guest->rsvp_status = $guestData['rsvpStatus'];
        $guest->rsvp_status_date = now();

        $guest->save();

        if (isset($guestData['menusSelections']) && is_array($guestData['menusSelections'])) {
            $menuItemIds = collect($guestData['menusSelections'])
                ->filter(fn($value) => is_numeric($value) && $value > 0)
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();


            $guest->selectedMenuItems()->sync($menuItemIds);
        }
    }

    /**
     * Revert the RSVP confirmation status of a guest and their companions.
     * @param Events $event
     * @return array
     */
    public function summary(Events $event): array
    {
        $guests = Guest::query()
            ->where('event_id',  $event->id)
            ->get(['id', 'event_id','parent_id', 'rsvp_status']);


        $selectedPlan = $event->eventPlan;

        return [
            'totalGuests' => $guests->count(),
            'confirmed' => $guests->where('rsvp_status', 'attending')->count(),
            'declined' => $guests->where('rsvp_status',  'not-attending')->count(),
            'pending' => $guests->where('rsvp_status',  'pending')->count(),
            'mainGuests' => $guests->whereNull('parent_id')->count(),
            'companions' => $guests->whereNotNull('parent_id')->count(),
            'totalAllowed' => $selectedPlan->max_guests,
        ];
    }

    /**
     * Retrieves a paginated list of RSVP guests for a specific event with optional
     * filtering by status and search term.
     *
     * @param Events $event
     * @return LengthAwarePaginator
     */
    public function getRsvpGuests(Events $event): LengthAwarePaginator
    {
        $perPage = $this->request->input('perPage', 10);
        $status = $this->request->input('status');
        $search = $this->request->input('search');

        return Guest::query()
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->with('companions')
            ->when($status, fn ($q) => $q->where('rsvp_status', $status))
            ->when($search, function ($q) use ($search) {
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
            ->orderByDesc('created_at')
            ->orderBy('id', 'DESC')
            ->paginate($perPage);
    }

    /**
     * Retrieves RSVP statistics for a given event.
     *
     * @param mixed $event The event object for which RSVP statistics are calculated.
     * @return array An associative array containing RSVP statistics, including:
     *               - 'attending': The number of guests with an RSVP status of 'attending'.
     *               - 'not_attending': The number of guests with an RSVP status of 'not-attending'.
     *               - 'pending': The number of guests with an RSVP status of 'pending'.
     *               - 'total_guests': The total number of unique guests.
     *               - 'total_attendees': The total number of attendees (including companions).
     *               - 'response_rate': The percentage of guests who have responded (RSVP status of 'attending' or 'not-attending').
     */
    public function getEventStatsWithCache($event): array
    {
        return Cache::remember(
            "event.{$event->id}.rsvp.stats",
            now()->addMinutes(10),
            function() use ($event) {
                return $this->getEventStats($event);
            }
        );
    }

    /**
     * Retrieves statistical data related to the RSVP statuses of guests and their companions for a given event.
     *
     * @param Events $event The event for which the stats are being calculated.
     * @return array An associative array containing the following statistical information:
     *               - 'attending': Attendance counts for guests, companions, and total.
     *               - 'pending': Pending RSVP counts for guests, companions, and total.
     *               - 'not_attending': Counts of guests and companions not attending, and their total.
     *               - 'totals': Totals for guests, companions, people, and the number of responded guests.
     *               - 'response_rate': Percentage of main guests who have responded.
     */
    public function getEventStats(Events $event): array
    {
        // 1. Get stats for MAIN GUESTS
        $mainGuestsStats = Guest::query()
            ->where('event_id', $event->id)
            ->whereNull('parent_id')  // Solo main guests
            ->select('rsvp_status')
            ->get()
            ->groupBy('rsvp_status');

        // 2. Get stats for COMPANIONS (independiente)
        $companionsStats = Guest::query()
            ->where('event_id', $event->id)
            ->whereNotNull('parent_id')  // Solo companions
            ->select('rsvp_status')
            ->get()
            ->groupBy('rsvp_status');

        // 3. Extract counts para main guests
        $attendingGuests = $mainGuestsStats->get(self::STATUS_ATTENDING, collect())->count();
        $pendingGuests = $mainGuestsStats->get(self::STATUS_PENDING, collect())->count();
        $notAttendingGuests = $mainGuestsStats->get(self::STATUS_NOT_ATTENDING, collect())->count();
        $totalGuests = $attendingGuests + $pendingGuests + $notAttendingGuests;

        // 4. Extract counts para companions
        $attendingCompanions = $companionsStats->get(self::STATUS_ATTENDING, collect())->count();
        $pendingCompanions = $companionsStats->get(self::STATUS_PENDING, collect())->count();
        $notAttendingCompanions = $companionsStats->get(self::STATUS_NOT_ATTENDING, collect())->count();
        $totalCompanions = $attendingCompanions + $pendingCompanions + $notAttendingCompanions;

        // 5. Calculate totals
        $totalPeople = $totalGuests + $totalCompanions;
        $respondedGuests = $attendingGuests + $notAttendingGuests;
        $responseRate = $totalGuests > 0
            ? round(($respondedGuests / $totalGuests) * 100)
            : 0;

        // 6. Build stats object
        return [
            'attending' => [
                'guests' => $attendingGuests,
                'companions' => $attendingCompanions,
                'total' => $attendingGuests + $attendingCompanions,
            ],
            'pending' => [
                'guests' => $pendingGuests,
                'companions' => $pendingCompanions,
                'total' => $pendingGuests + $pendingCompanions,
            ],
            'not_attending' => [
                'guests' => $notAttendingGuests,
                'companions' => $notAttendingCompanions,
                'total' => $notAttendingGuests + $notAttendingCompanions,
            ],
            'totals' => [
                'guests' => $totalGuests,
                'companions' => $totalCompanions,
                'people' => $totalPeople,
                'responded' => $respondedGuests,
            ],
            'response_rate' => $responseRate,
        ];
    }

}
