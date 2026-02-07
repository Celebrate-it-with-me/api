<?php

namespace App\Http\Services\AppServices;

use App\Models\Guest;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SuggestedMusicVoteServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get Main Guest from accessCode
     * (if code belongs to companion, return the parent)
     */
    protected function getMainGuestFromCode(string $accessCode, int $eventId): Guest
    {
        $guest = Guest::where('code', $accessCode)
            ->where('event_id', $eventId)
            ->first();

        if (!$guest) {
            throw ValidationException::withMessages([
                'accessCode' => 'Invalid access code for this event.'
            ]);
        }

        // If guest is a companion (has parent_id), get the parent (main guest)
        if ($guest->parent_id !== null) {
            $mainGuest = $guest->parent;

            if (!$mainGuest) {
                throw ValidationException::withMessages([
                    'accessCode' => 'Main guest not found.'
                ]);
            }

            return $mainGuest;
        }

        // Guest is already main guest (parent_id is null)
        return $guest;
    }

    /**
     * Get available votes for a main guest (by accessCode)
     */
    public function getAvailableVotes(string $accessCode, int $eventId): array
    {
        $mainGuest = $this->getMainGuestFromCode($accessCode, $eventId);

        // Total votes = 1 (main guest) + companions count
        $totalVotes = 1 + $mainGuest->companions()->count();

        // Votes used = current active votes by this main guest
        $votesUsed = SuggestedMusicVote::where('guest_id', $mainGuest->id)
            ->count();

        $available = max(0, $totalVotes - $votesUsed);

        return [
            'availableVotes' => $available,
            'totalVotes' => $totalVotes,
            'votesUsed' => $votesUsed,
            'companionsCount' => $mainGuest->companions()->count(),
        ];
    }

    /**
     * Store or update vote (with toggle logic) - using accessCode
     * @throws Throwable
     */
    public function storeOrUpdate(SuggestedMusic $suggestedMusic, string $accessCode): array
    {
        $mainGuest = $this->getMainGuestFromCode($accessCode, $suggestedMusic->event_id);
        $direction = $this->request->get('direction'); // 'up' or 'down'

        return DB::transaction(function() use ($suggestedMusic, $mainGuest, $direction) {
            // Find existing vote
            $existingVote = SuggestedMusicVote::where('suggested_music_id', $suggestedMusic->id)
                ->where('guest_id', $mainGuest->id)
                ->first();

            // Scenario 1: No existing vote - CREATE NEW
            if (!$existingVote) {
                // Check if user has available votes
                $totalVotes = 1 + $mainGuest->companions()->count();
                $votesUsed = SuggestedMusicVote::where('guest_id', $mainGuest->id)->count();
                $available = max(0, $totalVotes - $votesUsed);

                if ($available <= 0) {
                    throw ValidationException::withMessages([
                        'votes' => 'You have no votes remaining.'
                    ]);
                }

                $vote = SuggestedMusicVote::create([
                    'suggested_music_id' => $suggestedMusic->id,
                    'guest_id' => $mainGuest->id,
                    'vote_type' => $direction,
                ]);

                return [
                    'action' => 'created',
                    'vote' => $vote,
                    'votesRemaining' => $this->calculateRemainingVotes($mainGuest),
                ];
            }

            // Scenario 2: Existing vote - TOGGLE or CHANGE

            // If clicking same button -> REMOVE vote (toggle off)
            if ($existingVote->vote_type === $direction) {
                $existingVote->delete();

                return [
                    'action' => 'removed',
                    'vote' => null,
                    'votesRemaining' => $this->calculateRemainingVotes($mainGuest),
                ];
            }

            // If clicking opposite button -> CHANGE vote type
            $existingVote->vote_type = $direction;
            $existingVote->save();

            return [
                'action' => 'updated',
                'vote' => $existingVote,
                'votesRemaining' => $this->calculateRemainingVotes($mainGuest),
            ];
        });
    }

    /**
     * Get user's vote on a specific song (using accessCode)
     */
    public function getUserVote(SuggestedMusic $suggestedMusic, string $accessCode): ?SuggestedMusicVote
    {
        $mainGuest = $this->getMainGuestFromCode($accessCode, $suggestedMusic->event_id);

        return SuggestedMusicVote::where('suggested_music_id', $suggestedMusic->id)
            ->where('guest_id', $mainGuest->id)
            ->first();
    }

    /**
     * Helper: Calculate remaining votes
     */
    protected function calculateRemainingVotes(Guest $mainGuest): int
    {
        $totalVotes = 1 + $mainGuest->companions()->count();
        $votesUsed = SuggestedMusicVote::where('guest_id', $mainGuest->id)->count();

        return max(0, $totalVotes - $votesUsed);
    }
}
