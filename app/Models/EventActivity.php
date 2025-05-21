<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class EventActivity extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'event_activities';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'type',
        'actor_id',
        'actor_type',
        'target_id',
        'target_type',
        'payload',
    ];
    
    protected $casts = [
      'payload' => 'array',
    ];
    
    /**
     * Define a relationship between the current model and the Events model.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
    
    /**
     * Define a polymorphic relationship for the current model.
     *
     * @return MorphTo
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
    
    /**
     * Define a polymorphic relationship.
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
    
    /**
     * Define a relationship to the User model.
     *
     * @return BelongsTo
     */
    public static function logActivity($eventId, $type, $actor = null, $target = null, $payload = [])
    {
        return self::query()->create([
            'event_id' => $eventId,
            'type' => $type,
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_id' => $actor ? $actor->id : null,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target ? $target->id : null,
            'payload' => $payload,
        ]);
    }
    
}
