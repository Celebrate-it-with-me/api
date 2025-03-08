<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use App\Models\Rsvp;
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
            
            $mainGuest = MainGuest::query()->findOrFail($requestGuest['id']);
            $this->saveGuest($requestGuest, $mainGuest);
            
            if (isset($requestGuest['companions'])) {
                foreach ($requestGuest['companions'] as $companion) {
                    $guestCompanion = GuestCompanion::query()->findOrFail($companion['id']);
                    
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
    private function saveGuest(mixed $companion, Model|Collection|GuestCompanion|null $guestCompanion): void {
        $guestCompanion->first_name = $companion['firstName'];
        $guestCompanion->last_name = $companion['lastName'];
        $guestCompanion->email = $companion['email'];
        $guestCompanion->phone_number = $companion['phoneNumber'];
        $guestCompanion->confirmed = $companion['confirmed'];
        $guestCompanion->meal_preference = $companion['mealPreference'];
        
        $guestCompanion->save();
    }
}
