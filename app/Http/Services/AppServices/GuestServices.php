<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\Guest;
use App\Models\MainGuest;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GuestServices
{
    protected MainGuest $guest;

    public function __construct(
        private readonly CalculateAccessCodeService $accessCodeService
    ) {
        $this->guest = new MainGuest();
    }

    /**
     * The request being handled right now. It is read on every use, never kept: a service that holds the
     * request it was built with keeps answering the first request when the app handles several.
     */
    protected function request(): Request
    {
        return app('request');
    }

    /**
     * Get event guests.
     */


    /**
     * Create event guest.
     * @param Events $event
     * @return Model|Builder
     * @throws Exception
     */
    public function create(Events $event): Model|Builder
    {
        $guestData = $this->request()->input('guest');
        $preferences = $this->request()->input('preferences', []);
        $namedCompanions = $this->request()->input('namedCompanions', []);
        $unnamedCompanions = (int) $this->request()->input('unnamedCompanions', 0);

        // The main guest and every companion count: once the event is published the plan's limit is a hard cap.
        app(GuestLimit::class)->assertCanAdd($event, 1 + count($namedCompanions) + max(0, $unnamedCompanions));

        $mainGuest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $guestData['name'],
            'email' => $guestData['email'] ?? null,
            'phone' => $guestData['phone'] ?? null,
            'gender' => $guestData['gender'] ?? null,
            'assigned_menu_id' => $guestData['menuSelected'] ?? null,
            'notes' => $preferences['notes'] ?? null,
            'rsvp_status' => 'pending',
            'code' => $this->accessCodeService->generateForEvent($event),
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
                    'gender' => $companion['gender'] ?? null,
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

        EventActivityLogger::log(
            $event->id,
            'guest_created',
            Auth::user(),
            $mainGuest,
            [
                'name' => $mainGuest->name,
                'email' => $mainGuest->email,
                'phone' => $mainGuest->phone,
                'code' => $mainGuest->code,
                'companions' => [
                    'named' => count($namedCompanions),
                    'unnamed' => $unnamedCompanions,
                ],
            ]
        );

        return $mainGuest;
    }

}
