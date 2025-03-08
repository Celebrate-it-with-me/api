<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\MainGuest;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SuggestedMusicServices
{
    protected Request $request;
    protected SuggestedMusic $suggestedMusic;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->suggestedMusic = new SuggestedMusic();
    }
    
    /**
     * Get event save the date.
     * @param Events $event
     * @return mixed
     */
    public function getSuggestedMusic(Events $event): mixed
    {
        $perPage = 5;
        $pageSelected = $this->request->input('pageSelected', 1);
        
        return SuggestedMusic::query()
            ->where('event_id', $event->id)
            ->orderBy('id', 'DESC')
            ->paginate($perPage, ['*'], 'guests', $pageSelected);
    }
    
    /**
     * Add Suggested Music.
     * @param Events $event
     * @return SaveTheDate|Model
     * @throws ValidationException
     */
    public function create(Events $event): Model|SuggestedMusic
    {
        $mainGuest = MainGuest::query()
            ->where('access_code', $this->request->input('accessCode'))
            ->first();
        
        if (!$mainGuest) {
            throw ValidationException::withMessages(['message' => 'Invalid access code!']);
        }
        
        return SuggestedMusic::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->get('title'),
            'artist' => $this->request->get('artist'),
            'album' => $this->request->get('album'),
            'platformId' => $this->request->get('platformId'),
            'platform'  => 'spotify',
            'thumbnailUrl' => $this->request->get('thumbnailUrl'),
            'suggested_by' => $mainGuest->id,
        ]);
    }
    
    /**
     * Remove the provided SuggestedMusic instance and return its clone.
     *
     * @param SuggestedMusic $suggestedMusic
     * @return SuggestedMusic
     */
    public function remove(SuggestedMusic $suggestedMusic): SuggestedMusic
    {
        $suggestedMusicClone = clone $suggestedMusic;
        $suggestedMusic->delete();
        
        return $suggestedMusicClone;
    }
}
