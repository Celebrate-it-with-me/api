<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use App\Models\PartyMember;
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

}
