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
     * @param Request $request
     * @param Events $event
     * @return LengthAwarePaginator
     */
    public function getRsvpGuests(Events $event): LengthAwarePaginator
    {
        $perPage = $this->request->input('perPage', 15);
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
            ->paginate($perPage ?? 10);
    }
}
