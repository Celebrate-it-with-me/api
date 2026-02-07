<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\SuggestedMusicVote;
use App\Support\Notifications\NotificationKeys;
use Illuminate\Support\Facades\Cache;

class SuggestedMusicVoteObserver
{
    public function created(SuggestedMusicVote $vote): void
    {
        $this->handleVote($vote);
    }

    public function updated(SuggestedMusicVote $vote): void
    {
        if ($vote->wasChanged('vote_type')) {
            $this->handleVote($vote);
        }
    }

    protected function handleVote(SuggestedMusicVote $vote): void
    {
        $suggestion = $vote->suggestedMusic;
        if (!$suggestion) return;

        $event = $suggestion->event;
        if (!$event) return;

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;
        if ($ownerUserId <= 0) return;

        // Deduplication/Spam prevention
        // group_key = event_id + suggestionId + hour window
        $hour = date('Y-m-d-H');
        $groupKey = "music_react_{$event->id}_{$suggestion->id}_{$hour}";

        // Simple dedupe: only notify once per group key per user reaction change
        // Actually, the requirement says avoid spam using group_key OR dedupe repeated reactions.
        // We'll provide the group_key in meta and use a cache-based lock to avoid immediate spamming.

        $lockKey = "notify_lock_{$groupKey}_{$vote->guest_id}";
        if (Cache::has($lockKey)) {
            return;
        }
        Cache::put($lockKey, true, 300); // 5 minute cooldown for the same person on same song

        $guest = $vote->mainGuest;
        $actor = [
            'type' => 'guest',
            'id' => (int) $vote->guest_id,
            'name' => $guest?->first_name ?? 'Guest',
            'avatar_url' => null,
        ];

        EventNotificationEvent::dispatch(
            NotificationKeys::MUSIC_REACTED,
            (int) $suggestion->event_id,
            (int) $suggestion->id,
            $actor,
            (int) $ownerUserId,
            [
                'vote_type' => $vote->vote_type,
                'song_title' => $suggestion->title,
                'group_key' => $groupKey
            ]
        );
    }
}
