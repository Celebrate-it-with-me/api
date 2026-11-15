<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the organizer did with one assistant step of one event. */
class AssistantTaskState extends Model
{
    public const DONE = 'done';

    public const HIDDEN = 'hidden';

    public const NOT_APPLICABLE = 'not_applicable';

    public const SNOOZED = 'snoozed';

    protected $table = 'assistant_task_states';

    protected $fillable = ['event_id', 'task_key', 'state', 'snoozed_until', 'set_by_user_id'];

    protected $casts = ['snoozed_until' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }
}
