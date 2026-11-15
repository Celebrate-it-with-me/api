<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A to-do the organizer wrote herself; plain text, listed in "Mi plan". */
class AssistantCustomTask extends Model
{
    public const MAX_PER_EVENT = 50;

    protected $table = 'assistant_custom_tasks';

    protected $fillable = ['event_id', 'title', 'notes', 'due_date', 'done_at', 'created_by_user_id'];

    protected $casts = ['due_date' => 'date', 'done_at' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }
}
