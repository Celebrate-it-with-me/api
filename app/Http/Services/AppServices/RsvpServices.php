<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use App\Models\Rsvp;
use App\Http\Resources\AppResources\GuestResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RsvpServices
{
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
     * Get RSVP summary for a specific event.
     *
     * @param Events $event The event to get summary for
     * @return array Summary statistics
     */
    public function summary(Events $event): array
    {
        // Fetch all guests for this event in a single query with only the needed columns
        $guests = Guest::query()
            ->where('event_id', $event->id)
            ->select('id', 'parent_id', 'rsvp_status')
            ->get();

        $selectedPlan = $event->eventPlan;

        // Calculate all statistics using collection methods
        return [
            'totalGuests' => $guests->count(),
            'confirmed' => $guests->where('rsvp_status', 'attending')->count(),
            'declined' => $guests->where('rsvp_status', 'not-attending')->count(),
            'pending' => $guests->where('rsvp_status', 'pending')->count(),
            'mainGuests' => $guests->whereNull('parent_id')->count(),
            'companions' => $guests->whereNotNull('parent_id')->count(),
            'totalAllowed' => $selectedPlan->max_guests,
        ];
    }

    /**
     * Get a paginated list of RSVP users for a specific event with optional filters.
     *
     * @param Events $event The event to get RSVP users for
     * @param int $perPage Number of items per page
     * @param string|null $requestStatus Status filter from request ('pending', 'confirmed', 'declined')
     * @param string|null $search Search term to filter by name, email, or phone
     * @return array Paginated list of guests with their companions
     */
    public function getUsersList(Events $event, int $perPage = 15, ?string $requestStatus = null, ?string $search = null): array
    {
        $status = $this->mapStatusFromRequest($requestStatus);

        $query = $this->buildGuestQuery($event, $status, $search);

        $paginatedGuests = $query->paginate($perPage);

        return GuestResource::collection($paginatedGuests)->response()->getData(true);
    }

    /**
     * Map the request status to the internal status value.
     *
     * @param string|null $requestStatus Status from request
     * @return string|null Mapped internal status
     */
    private function mapStatusFromRequest(?string $requestStatus): ?string
    {
        if ($requestStatus === 'pending') {
            return 'pending';
        } else if ($requestStatus === 'confirmed') {
            return 'attending';
        } else if ($requestStatus === 'declined') {
            return 'not-attending';
        }

        return null;
    }

    /**
     * Build the query for retrieving guests with optional filters.
     *
     * @param Events $event The event to get guests for
     * @param string|null $status Status filter
     * @param string|null $search Search term
     * @return \Illuminate\Database\Eloquent\Builder Query builder instance
     */
    private function buildGuestQuery(Events $event, ?string $status, ?string $search)
    {
        return Guest::query()
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->when($status && $status !== '', function ($q) use ($status) {
                $q->where(function ($q) use ($status) {
                    $q->where('rsvp_status', $status)
                        ->orWhereHas('companions', function($sub) use ($status) {
                            $sub->where('rsvp_status', $status);
                        });
                });
            })
            ->with('companions')
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
            ->orderByDesc('created_at');
    }

    /**
     * Get RSVP user totals for a specific event.
     *
     * @param Events $event The event to get totals for
     * @return array Totals data
     */
    public function getUsersTotals(Events $event): array
    {
        // Fetch all guests for this event in a single query
        $guests = Guest::query()
            ->where('event_id', $event->id)
            ->select('id', 'parent_id', 'rsvp_status')
            ->get();

        // Calculate totals using collection methods instead of multiple queries
        $totalGuests = $guests->count();
        $totalMainGuests = $guests->whereNull('parent_id')->count();
        $totalCompanions = $guests->whereNotNull('parent_id')->count();
        $totalPending = $guests->where('rsvp_status', 'pending')->count();
        $totalConfirmed = $guests->where('rsvp_status', 'attending')->count();
        $totalDeclined = $guests->where('rsvp_status', 'not-attending')->count();

        return [
            'message' => 'Rsvp totals retrieved.',
            'data' => [
                'totalGuests' => $totalGuests,
                'totalMainGuests' => $totalMainGuests,
                'totalCompanions' => $totalCompanions,
                'totalPending' => $totalPending,
                'totalConfirmed' => $totalConfirmed,
                'totalDeclined' => $totalDeclined,
            ],
        ];
    }
}
