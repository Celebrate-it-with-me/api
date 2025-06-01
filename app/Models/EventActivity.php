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
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    /**
     * Define a polymorphic relationship for the current model.
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

    /**
     * Get the message attribute based on the activity type.
     *
     * This method dynamically generates a message based on the type of activity
     * and the associated payload data.
     */
    public function getMessageAttribute(): object|array|string|null
    {
        return match ($this->type) {
            'event_created' => __('Event :name, has been created by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'event_updated' => __('Event :name, has been updated by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'guest_created' => __('Guest :name, has been created by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'guest_updated' => __('Guest :name, has been updated by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'guest_deleted' => __('Guest :name, has been deleted by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'menu_created' => __('Menu :name, has been created by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'menu_updated' => __('Menu :name, has been updated by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'menu_item_created' => __('Menu item :name, has been created by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'menu_item_updated' => __('Menu item :name, has been updated by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),
            'menu_item_deleted' => __('Menu item :name, has been deleted by :user', [
                'name' => $this->payload['name'],
                'user' => $this->actor ? $this->actor->name : 'System',
            ]),

            'guest_confirmed' => __('Guest :name, has confirmed their attendance.', ['name' => $this->payload['name']]),
            'photo_uploaded' => __('Photo uploaded by :name.', ['name' => $this->payload['name']]),
            'music_added' => __('Music added by :name.', ['name' => $this->payload['name']]),
            default => __('Activity logged.'),
        };
    }

    public function getIconAttribute(): string
    {
        return match ($this->type) {
            'event_created' => 'party-popper',
            'event_updated' => 'edit',
            'guest_created', 'guest_added' => 'user-plus',
            'guest_updated' => 'user-check',
            'guest_deleted' => 'user-minus',
            'guest_confirmed' => 'user-round',
            'guest_declined' => 'user-x',
            'menu_created' => 'utensils',
            'menu_item_created' => 'plus-circle',
            'menu_item_deleted' => 'trash-2',
            'photo_uploaded' => 'image',
            'music_added' => 'music',
            'location_added' => 'map-pin',
            default => 'activity',
        };
    }

    public function getColorAttribute(): string
    {
        return match ($this->type) {
            'event_created' => 'indigo',
            'event_updated', 'guest_added' => 'blue',
            'guest_created', 'guest_confirmed', 'menu_item_created' => 'green',
            'guest_updated', 'music_added' => 'yellow',
            'guest_deleted', 'guest_declined' => 'red',
            'menu_created' => 'orange',
            'menu_item_deleted' => 'rose',
            'photo_uploaded' => 'pink',
            'location_added' => 'cyan',
            default => 'gray',
        };
    }
}
