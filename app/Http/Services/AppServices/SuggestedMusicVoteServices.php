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
     * Create Suggested Music Vote.
     * @param SuggestedMusic $suggestedMusic
     * @return SaveTheDate|Model
     */
    public function create(SuggestedMusic $suggestedMusic): Model|SuggestedMusic
    {
        return SuggestedMusicVote::query()->create([
            'suggested_music_id' => $suggestedMusic->id,
            'main_guest_id' => $this->request->user()->id,
            'vote_type' => $this->request->get('voteType'),
        ]);
    }
    
    /**
     * @param SuggestedMusicVote $suggestedMusicVote
     * @return SuggestedMusicVote
     */
    public function remove(SuggestedMusicVote $suggestedMusicVote): SuggestedMusicVote
    {
        $suggestedMusicVoteClone = clone $suggestedMusicVote;
        $suggestedMusicVoteClone->delete();
        
        return $suggestedMusicVoteClone;
    }
}
