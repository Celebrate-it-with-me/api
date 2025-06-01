<?php

namespace App\Observers;

use App\Models\GuestCompanion;

class GuestCompanionObserver
{
    /**
     * Handle the GuestCompanion "created" event.
     */
    public function created(GuestCompanion $guestCompanion): void
    {
        //
    }

    /**
     * Handle the GuestCompanion "updated" event.
     */
    public function updated(GuestCompanion $guestCompanion): void
    {
        //
    }

    /**
     * Handle the GuestCompanion "deleted" event.
     * Updating companions qty if one companion is deleted.
     */
    public function deleted(GuestCompanion $guestCompanion): void
    {
        $countCompanions = GuestCompanion::query()
            ->where('main_guest_id', $guestCompanion->main_guest_id)
            ->count();

        $guestCompanion->mainGuest->companion_qty = $countCompanions;
        $guestCompanion->mainGuest->save();
    }

    /**
     * Handle the GuestCompanion "restored" event.
     */
    public function restored(GuestCompanion $guestCompanion): void
    {
        //
    }

    /**
     * Handle the GuestCompanion "force deleted" event.
     */
    public function forceDeleted(GuestCompanion $guestCompanion): void
    {
        //
    }
}
