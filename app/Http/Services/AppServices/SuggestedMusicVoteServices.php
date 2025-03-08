<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicVote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class SuggestedMusicVoteServices
{
    protected Request $request;
    protected SuggestedMusicVote $suggestedMusicVote;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->suggestedMusicVote = new SuggestedMusicVote();
    }
    
    /**
     * Get event suggested music votes.
     * @param SuggestedMusic $suggestedMusic
     * @return HasMany
     */
    public function getMusicVote(SuggestedMusic $suggestedMusic): HasMany
    {
        return $suggestedMusic->musicVotes();
    }
    
    /**
     * Handles the creation or updating of a music vote for a suggested music item.
     *
     * This method checks if a vote by the currently authenticated user already exists for
     * the specified suggested music. If it does, the vote type is updated. If no such
     * vote exists, a new vote is created with the corresponding data.
     *
     * @param SuggestedMusic $suggestedMusic The suggested music instance for which the vote is being processed.
     * @return SuggestedMusicVote The updated or newly created music vote instance.
     */
    public function storeOrUpdate(SuggestedMusic $suggestedMusic): SuggestedMusicVote
    {
        $mainGuestId = $this->request->user()->id;
        
        $musicVote = SuggestedMusicVote::query()
            ->where('suggested_music_id', $suggestedMusic->id)
            ->where('main_guest_id', $mainGuestId)
            ->first();
        
        if ($musicVote) {
            $musicVote->vote_type = $this->request->get('direction');
            $musicVote->save();
            
            return $musicVote;
        }
        
        return SuggestedMusicVote::query()
            ->create([
                'main_guest_id' => $mainGuestId,
                'vote_type' => $this->request->get('direction'),
                'suggested_music_id' => $suggestedMusic->id
            ]);
    }
}
