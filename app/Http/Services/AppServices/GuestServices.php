<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GuestServices
{
    protected Request $request;
    protected MainGuest $guest;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->guest = new MainGuest();
    }

    /**
     * Get event guests.
     */
    public function getEventsGuests(Events $event): Collection
    {
        $searchValue = $this->request->input('searchValue');
        
        return Guest::query()
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->when($searchValue, function (Builder $query, $searchValue) {
                $query->where(function (Builder $q) use ($searchValue) {
                    $q->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('email', 'like', "%{$searchValue}%")
                        ->orWhere('phone', 'like', "%{$searchValue}%");
                });
            })
            ->withCount('companions')
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    
    /**
     * Create event guest.
     * @param Events $event
     * @return Model|Builder
     * @throws Exception
     */
    public function create(Events $event): Model|Builder
    {
        $guestData = $this->request->input('guest');
        $preferences = $this->request->input('preferences', []);
        $namedCompanions = $this->request->input('namedCompanions', []);
        $unnamedCompanions = (int) $this->request->input('unnamedCompanions', 0);
        
        Log::info('checking guest data', [$guestData['name']]);
        
        $mainGuest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $guestData['name'],
            'email' => $guestData['email'] ?? null,
            'phone' => $guestData['phone'] ?? null,
            'assigned_menu_id' => $guestData['menuSelected'] ?? null,
            'meal_preference' => $preferences['meal_preference'] ?? null,
            'allergies' => $preferences['allergies'] ?? null,
            'notes' => $preferences['notes'] ?? null,
            'rsvp_status' => 'pending',
            'code' => $this->calculateAccessCode(),
        ]);
        
        if (!$mainGuest) {
            throw new \Exception('Failed to create the main guest.');
        }
        
        if (count($namedCompanions) > 0) {
            foreach ($namedCompanions as $companion) {
                Guest::query()->create([
                    'event_id' => $event->id,
                    'parent_id' => $mainGuest->id,
                    'name' => $companion['name'],
                    'email' => $companion['email'] ?? null,
                    'phone' => $companion['phone'] ?? null,
                    'assigned_menu_id' => $guestData['menuSelected'] ?? null,
                    'rsvp_status' => 'pending',
                ]);
            }
        }
        
        
        if ($unnamedCompanions > 0) {
            for ($i = 0; $i < $unnamedCompanions; $i++) {
                Guest::query()->create([
                    'event_id' => $event->id,
                    'parent_id' => $mainGuest->id,
                    'name' => 'Unnamed',
                    'email' => null,
                    'phone' => null,
                    'rsvp_status' => 'pending',
                ]);
            }
        }
        
        
        return $mainGuest;
    }
    
    
    /**
     * Auto generate access code.
     * @return string
     */
    private function calculateAccessCode(): string
    {
        $code = Str::upper(Str::random(2)); // Dos letras aleatorias
        $eventId = $this->request->input('eventId');
        
        do {
            $randomNumber = random_int(1000, 9999);
            $fullCode = $code . $randomNumber;
            
            $isUnique = !Guest::query()
                ->where('event_id', $eventId)
                ->where('code', $fullCode)
                ->exists();
        } while (!$isUnique);
        
        return $fullCode;
    }
    
    /**
     * Updates the companion type for the specified main guest.
     *
     * @param MainGuest $mainGuest The main guest instance whose companion type is being updated.
     * @param Request $request The HTTP request containing the data for the companion type update.
     *
     * @return MainGuest The updated main guest instance.
     */
    public function updateCompanionType(MainGuest $mainGuest, Request $request): MainGuest
    {
        $requestArray = $request->all();
        
        if (count($requestArray)) {
            foreach ($requestArray as $key => $value) {
                $snakeCaseKey = Str::snake($key);
                $mainGuest->{$snakeCaseKey} = $value;
                
                if ($snakeCaseKey === 'companionType') {
                    $this->deleteCompanions($value, $mainGuest);
                }
            }
        }
        
        $mainGuest->save();
        
        $mainGuest->refresh();
        return $mainGuest;
    }
    
    /**
     * Deletes the companions associated with the specified main guest based on the companion type.
     *
     * @param string $companionType The type of companion to evaluate for deletion.
     * @param MainGuest $mainGuest The main guest instance whose companions may be deleted.
     *
     * @return void
     */
    private function deleteCompanions(string $companionType, MainGuest $mainGuest): void
    {
        if ($companionType === 'no_named') {
            GuestCompanion::query()
                ->where('main_guest_id', $mainGuest->id)
                ->delete();
        }
    }
    
    /**
     * Deletes the specified guest from the database.
     *
     * @param Guest $guest The guest instance to be deleted.
     * @return void
     */
    public function delete(Guest $guest): void
    {
        $guest->delete();
    }
    
    public function showGuest(Guest $guest): Guest
    {
        return $guest->load([
            'companions' => function ($query) {
                $query->orderBy('created_at', 'desc');
            },
            'event',
            'invitations' => function ($query) {
                $query->orderBy('created_at', 'desc');
            },
            'rsvpLogs' => function ($query) {
                $query->orderBy('created_at', 'desc');
            },
        ]);
    }

}
