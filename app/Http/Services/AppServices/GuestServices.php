<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\MainGuest;
use App\Models\PartyMember;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * Get user logged events.
     * @return Collection
     */
    public function getEventsGuests(Events $event): Collection
    {
        return Mainguest::query()
            ->where('event_id', $event->id)
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
        ]);
        
        if (!$mainGuest) {
            throw new Exception('Error creating the main guest!');
        }
        
        $partyMembers = $this->request->input('companionList');
        if (count(($partyMembers))) {
            foreach ($partyMembers as $member) {
                PartyMember::query()->create([
                    'main_guest_id' => $mainGuest->id,
                    'name'  => "{$member['firstName']} {$member['lastName']}",
                    'confirmed' => 'unused',
                    'email' => $member['email'],
                    'phone_number' => $member['phoneNumber'],
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
        
        $code .= Str::upper(Str::substr($this->request->input('phoneNumber'), -2));
        
        return $code;
    }

}
