<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use App\Models\User;
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
        $this->suggestedMusic = new SuggestedMusic;
    }

    /**
     * Get event save the date.
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
     *
     * @return SaveTheDate|Model
     *
     * @throws ValidationException
     */
    public function create(Events $event): Model|SuggestedMusic
    {
        if ($this->request->input('accessCode') === 'organizer') {
            $suggestedBy = $this->request->user();
            $suggestedByEntity = User::class;
        } else {
            $suggestedBy = Guest::query()
                ->where('code', $this->request->input('accessCode'))
                ->first();
            $suggestedByEntity = Guest::class;
        }

        if (! $suggestedBy) {
            throw ValidationException::withMessages(['message' => 'Invalid access code!']);
        }

        return SuggestedMusic::query()->create([
            'event_id' => $event->id,
            'title' => $this->request->get('title'),
            'artist' => $this->request->get('artist'),
            'album' => $this->request->get('album'),
            'platformId' => $this->request->get('platformId'),
            'platform' => 'spotify',
            'thumbnailUrl' => $this->request->get('thumbnailUrl'),
            'suggested_by_entity' => $suggestedByEntity,
            'suggested_by_id' => $suggestedBy->id,
        ]);
    }

    /**
     * Remove the provided SuggestedMusic instance and return its clone.
     */
    public function remove(SuggestedMusic $suggestedMusic): SuggestedMusic
    {
        $suggestedMusicClone = clone $suggestedMusic;
        $suggestedMusic->delete();

        return $suggestedMusicClone;
    }
}
