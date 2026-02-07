<?php

namespace App\Observers;

use App\Events\SuggestedMusicCreated;
use App\Models\SuggestedMusic;
use Illuminate\Support\Facades\Log;

class SuggestedMusicObserver
{
    /**
     * Handle the SuggestedMusic "created" event.
     */
    public function created(SuggestedMusic $suggestedMusic): void
    {
        $event = $suggestedMusic->event;

        if (!$event) {
            return;
        }

        $actorModel = $suggestedMusic->suggestedBy;

        $actor = [
            'type' => $suggestedMusic->suggested_by_entity,
            'id' => $suggestedMusic->suggested_by_id,
            'name' => $actorModel?->name ?? 'guest',
        ];

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id
            ?? 0;

        if ($ownerUserId <= 0) {
            return;
        }

        Log::info('Suggested music created event dispatched', [$suggestedMusic->event_id]);

        SuggestedMusicCreated::dispatch(
            (int) $suggestedMusic->event_id,
            (int) $suggestedMusic->id,
            $actor,
            (string) $suggestedMusic->title,
            $suggestedMusic->artist ? (string) $suggestedMusic->artist : null,
            $ownerUserId
        );

    }

    /**
     * Handle the SuggestedMusic "updated" event.
     */
    public function updated(SuggestedMusic $suggestedMusic): void
    {
        //
    }

    /**
     * Handle the SuggestedMusic "deleted" event.
     */
    public function deleted(SuggestedMusic $suggestedMusic): void
    {
        //
    }

    /**
     * Handle the SuggestedMusic "restored" event.
     */
    public function restored(SuggestedMusic $suggestedMusic): void
    {
        //
    }

    /**
     * Handle the SuggestedMusic "force deleted" event.
     */
    public function forceDeleted(SuggestedMusic $suggestedMusic): void
    {
        //
    }
}
