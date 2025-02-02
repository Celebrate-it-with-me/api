<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Rsvp;
use Illuminate\Http\Request;

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
}
