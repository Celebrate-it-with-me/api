<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SuggestedMusic extends Model
{
    protected $table = 'suggested_music';

    protected $fillable = [
        'event_id',
        'title',
        'artist',
        'album',
        'platform',
        'platformId',
        'thumbnailUrl',
        'previewUrl',
        'suggested_by_entity',
        'suggested_by_id'
    ];

    /**
     * Relationships
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    /**
     * Suggested By
     * @return MorphTo
     */
    public function suggestedBy(): MorphTo
    {
        return $this->morphTo(
          name: 'suggestedBy',
          type: 'suggested_by_entity',
          id: 'suggested_by_id'
        );
    }

    public function musicVotes(): HasMany
    {
        return $this->hasMany(SuggestedMusicVote::class, 'suggested_music_id', 'id');
    }

    /**
     * Helper Methods
     */

    /**
     * Get total upvotes for this song
     */
    public function getUpvotesCountAttribute(): int
    {
        return $this->musicVotes()->where('vote_type', 'up')->count();
    }

    /**
     * Get total downvotes for this song
     */
    public function getDownvotesCountAttribute(): int
    {
        return $this->musicVotes()->where('vote_type', 'down')->count();
    }

    /**
     * Get net score (upvotes - downvotes)
     */
    public function getNetScoreAttribute(): int
    {
        return $this->upvotesCount - $this->downvotesCount;
    }

    /**
     * Check if main guest has voted on this song
     */
    public function getVoteByGuest(int $guestId): ?SuggestedMusicVote
    {
        return $this->musicVotes()
            ->where('guest_id', $guestId)
            ->first();
    }

    /**
     * Check if song was suggested by organizer
     */
    public function isSuggestedByOrganizer(): bool
    {
        return $this->suggested_by_entity === 'user';
    }

    /**
     * Scopes
     */

    /**
     * Filter by event
     */
    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Order by popularity (net score)
     */
    public function scopePopular(Builder $query): Builder
    {
        return $query->withCount([
            'musicVotes as upvotes' => fn($q) => $q->where('vote_type', 'up'),
            'musicVotes as downvotes' => fn($q) => $q->where('vote_type', 'down'),
        ])->orderByRaw('(upvotes - downvotes) DESC');
    }

    /**
     * Order by most recent
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'DESC');
    }
}
