<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
    public function getEventsGuests(Events $event): LengthAwarePaginator
    {
        $perPage = $this->request->input('perPage', 10);
        $pageSelected = $this->request->input('pageSelected', 1);
        
        return Mainguest::query()
            ->when($this->request->filled('searchValue'), function (Builder $query) {
                $searchValue = $this->request->input('searchValue');
                $query->where(function (Builder $innerQuery) use ($searchValue) {
                    $innerQuery->where('first_name', 'like', "%$searchValue%")
                        ->orWhere('last_name', 'like', "%$searchValue%")
                        ->orWhere('email', 'like', "%$searchValue%");
                });
            })
            ->where('event_id', $event->id)
            ->paginate($perPage, ['*'], 'guests', $pageSelected);
    }
    
    /**
     * Create event guest.
     * @param Events $event
     * @return Model|Builder
     * @throws Exception
     */
    public function create(Events $event): Model|Builder
    {
        $companionsQty = $this->request->input('companionType') === 'no_name'
            ? $this->request->input('companionQty')
            : count($this->request->input('companionList'));
        
        $mainGuest =  MainGuest::query()->create([
            'event_id' => $event->id,
            'first_name' => $this->request->input('firstName'),
            'last_name' => $this->request->input('lastName'),
            'email' => $this->request->input('email'),
            'phone_number' => $this->request->input('phoneNumber'),
            'access_code' => $this->calculateAccessCode(),
            'code_used_times' => 0,
            'confirmed' => 'unused',
            'confirmed_date' => null,
            'companion_type' => $this->request->input('companionType') ?? 'no_companion',
            'companion_qty' => $companionsQty,
        ]);
        
        if (!$mainGuest) {
            throw new Exception('Error creating the main guest!');
        }
        
        $partyMembers = $this->request->input('companionList');
        if (count($partyMembers)) {
            foreach ($partyMembers as $member) {
                GuestCompanion::query()->create([
                    'main_guest_id' => $mainGuest->id,
                    'first_name' => $member['firstName'],
                    'last_name' => $member['lastName'],
                    'email' => $member['email'],
                    'phone_number' => $member['phoneNumber'],
                    'confirmed' => 'pending',
                    'confirmed_date' => null,
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
        $code = Str::upper(Str::substr($this->request->input('firstName'), 0, 1));
        
        $code .= Str::upper(Str::substr($this->request->input('lastName'), 0, 1));
        
        do {
            $randomNumber = random_int(1000, 9999);
            $isUnique = !MainGuest::query()
                ->where('event_id', $this->request->input('eventId'))
                ->where('access_code', $code . $randomNumber)
                ->exists();
        } while (!$isUnique);
        
        $code .= $randomNumber;
        
        return $code;
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

}
