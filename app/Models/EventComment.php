<?php

namespace App\Models;

use App\Enums\EventCommentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventComment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'event_comments';

    protected $fillable = [
        'event_id',

        // New polymorphic author
        'authorable_type',
        'authorable_id',

        // Content
        'comment',

        // Moderation + UX
        'status',
        'is_pinned',
        'is_favorite',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_favorite' => 'boolean',
        'status' => EventCommentStatus::class,
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    public function authorable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Query scopes
     */
    public function scopeVisible($query)
    {
        return $query->where('status', EventCommentStatus::VISIBLE->value);
    }

    public function scopeHidden($query)
    {
        return $query->where('status', EventCommentStatus::HIDDEN->value);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeForEvent($query, Events $event)
    {
        return $query->where('event_id', $event->id);
    }

    public function scopeWithStatus($query, $status)
    {
        if (!$status) return $query;

        $normalizedStatus = $status instanceof EventCommentStatus
            ? $status->value
            : EventCommentStatus::from((string) $status)->value;

        return $query->where('status', $normalizedStatus);
    }

    public function scopeFavorite($query, ?bool $favorite = null)
    {
        return is_null($favorite) ? $query : $query->where('is_favorite', $favorite);
    }
}
