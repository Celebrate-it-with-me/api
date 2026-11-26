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
     * The types the organizer's feed shows, each with its module. Others (and the per-field edit log of the
     * invitation editor, which would flood the feed) stay in the table but are not shown: a sentence must be readable.
     */
    public const FEED_TYPES = [
        'event_created' => 'event', 'event_updated' => 'event', 'event_published' => 'event', 'event_unpublished' => 'event',
        'event_archived' => 'event', 'event_restored' => 'event',
        'guest_created' => 'guests', 'guest_updated' => 'guests', 'guest_deleted' => 'guests', 'guests_imported' => 'guests',
        'guest_confirmed' => 'rsvp', 'guest_declined' => 'rsvp', 'guest_rsvp_changed' => 'rsvp', 'invitation_opened' => 'rsvp',
        'menu_created' => 'menu', 'menu_updated' => 'menu', 'menu_deleted' => 'menu',
        'menu_item_created' => 'menu', 'menu_item_updated' => 'menu', 'menu_item_deleted' => 'menu',
        'invitation_template_changed' => 'invitation', 'invitation_template_undone' => 'invitation',
        'invitation_cover_uploaded' => 'invitation', 'invitation_cover_removed' => 'invitation',
        'invitation_image_uploaded' => 'invitation', 'invitation_image_removed' => 'invitation',
        'comment_added' => 'comments', 'music_suggested' => 'music',
    ];

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
        $impersonatedBy = request()->attributes->get('impersonated_by');

        return self::query()->create([
            'event_id' => $eventId,
            'type' => $type,
            // The app enforces morph aliases (`user`, `guest`): the class name cannot be read back.
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor ? $actor->id : null,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target ? $target->id : null,
            // Done by staff impersonating the user: the feed says "Soporte" and the payload keeps who it really was.
            'payload' => $impersonatedBy ? $payload + ['impersonated_by' => $impersonatedBy] : $payload,
        ]);
    }

    /** Who did it, for the feed: "Soporte" when staff did it while impersonating. */
    public function actorName(): string
    {
        if (! empty($this->payload['impersonated_by'])) {
            return config('impersonation.staff_label');
        }

        return $this->actor ? $this->actor->name : __('System');
    }

    /**
     * What the app can show next to the sentence: the module, a name (guest, menu, template, event) and a count
     * (guests imported, companions who come). Only these; never contact data, codes or ids of other people.
     *
     * @return array{module: ?string, name: ?string, count: ?int}
     */
    public function getMetaAttribute(): array
    {
        $payload = (array) $this->payload;
        $name = $payload['name'] ?? $payload['title'] ?? $payload['template'] ?? $payload['event_name'] ?? null;
        $count = match ($this->type) {
            'guests_imported' => $payload['count'] ?? null,
            'guest_confirmed' => $payload['companions'] ?? null,
            default => null,
        };

        return [
            'module' => self::FEED_TYPES[$this->type] ?? null,
            'name' => is_string($name) && $name !== '' ? $name : null,
            'count' => $count !== null ? (int) $count : null,
        ];
    }

    /**
     * The pieces of the sentence, so the app can translate it with its own i18n instead of using `message`:
     * who did it (`actor`, "Soporte" for staff impersonating) and the data the sentence needs, never contact data or ids.
     *
     * @return array{actor: string, name: ?string, template: ?string, song: ?string, count: ?int, companions: ?int, status: ?string}
     */
    public function getParamsAttribute(): array
    {
        $payload = (array) $this->payload;
        $text = fn (string ...$keys): ?string => collect($keys)->map(fn ($k) => $payload[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '');
        $number = fn (string $key): ?int => isset($payload[$key]) ? (int) $payload[$key] : null;

        return [
            'actor' => $this->actorName(),
            'name' => $text('name', 'title', 'event_name'),
            'template' => $text('template'),
            'song' => $text('song'),
            'count' => $number('count'),
            'companions' => $number('companions'),
            'status' => $this->type === 'guest_rsvp_changed' ? ($payload['to'] ?? null) : null,
        ];
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
            'event_created' => __('Event :name has been created by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'event_updated' => __('Event :name has been updated by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'event_published' => __('Event :name has been published by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'event_unpublished' => __('Event :name is back to draft, by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'event_archived' => __('Event :name has been archived by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'event_restored' => __('Event :name has been restored by :user', [
                'name' => $this->payload['event_name'] ?? '',
                'user' => $this->actorName(),
            ]),
            'guest_created' => __('Guest :name has been created by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'guest_updated' => __('Guest :name has been updated by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'guest_deleted' => __('Guest :name has been deleted by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_created' => __('Menu :name has been created by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_updated' => __('Menu :name has been updated by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_deleted' => __('Menu :name has been deleted by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_item_created' => __('Menu item :name has been created by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_item_updated' => __('Menu item :name has been updated by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),
            'menu_item_deleted' => __('Menu item :name has been deleted by :user', [
                'name' => ($this->payload['name'] ?? $this->payload['title'] ?? ''),
                'user' => $this->actorName(),
            ]),

            'guests_imported' => __(':count guests imported by :user', [
                'count' => $this->payload['count'] ?? 0,
                'user' => $this->actorName(),
            ]),
            'guest_confirmed' => ($companions = (int) ($this->payload['companions'] ?? 0)) > 0
                ? trans_choice('Guest :name has confirmed their attendance with :count companion|Guest :name has confirmed their attendance with :count companions', $companions, ['name' => ($this->payload['name'] ?? '')])
                : __('Guest :name has confirmed their attendance.', ['name' => ($this->payload['name'] ?? $this->payload['title'] ?? '')]),
            'guest_declined' => __('Guest :name has declined the invitation.', ['name' => $this->payload['name'] ?? '']),
            'guest_rsvp_changed' => __('Guest :name changed their answer to ":status".', [
                'name' => $this->payload['name'] ?? '',
                'status' => match ($this->payload['to'] ?? null) {
                    'attending' => __('attending'),
                    'not-attending' => __('not attending'),
                    default => __('pending'),
                },
            ]),
            'invitation_opened' => __('Guest :name opened their invitation.', ['name' => $this->payload['name'] ?? '']),
            'invitation_template_changed' => __('Invitation template changed to :template by :user', [
                'template' => $this->payload['template'] ?? '',
                'user' => $this->actorName(),
            ]),
            'invitation_template_undone' => __('Invitation template change undone by :user', ['user' => $this->actorName()]),
            'invitation_cover_uploaded' => __('Cover photo of the invitation updated by :user', ['user' => $this->actorName()]),
            'invitation_cover_removed' => __('Cover photo of the invitation removed by :user', ['user' => $this->actorName()]),
            'invitation_image_uploaded' => __('Picture of a section of the invitation updated by :user', ['user' => $this->actorName()]),
            'invitation_image_removed' => __('Picture of a section of the invitation removed by :user', ['user' => $this->actorName()]),
            'comment_added' => __('Guest :name left a comment.', ['name' => $this->payload['name'] ?? '']),
            'music_suggested' => __('Guest :name suggested the song ":song".', [
                'name' => $this->payload['name'] ?? '',
                'song' => $this->payload['song'] ?? '',
            ]),
            'photo_uploaded' => __('Photo uploaded by :name.', ['name' => ($this->payload['name'] ?? $this->payload['title'] ?? '')]),
            'music_added' => __('Music added by :name.', ['name' => ($this->payload['name'] ?? $this->payload['title'] ?? '')]),
            default => __('Activity logged.'),
        };
    }

    public function getIconAttribute(): string
    {
        return match ($this->type) {
            'event_created' => 'party-popper',
            'event_updated' => 'edit',
            'event_published' => 'send',
            'event_unpublished' => 'eye-off',
            'event_archived' => 'archive',
            'event_restored' => 'archive-restore',
            'guest_created', 'guest_added' => 'user-plus',
            'guest_updated' => 'user-check',
            'guest_deleted' => 'user-minus',
            'guest_confirmed' => 'user-round',
            'guest_declined' => 'user-x',
            'guest_rsvp_changed' => 'user-check',
            'invitation_opened' => 'eye',
            'invitation_template_changed', 'invitation_template_undone' => 'edit',
            'invitation_cover_uploaded', 'invitation_image_uploaded' => 'image',
            'invitation_cover_removed', 'invitation_image_removed' => 'image-off',
            'comment_added' => 'message-circle',
            'music_suggested' => 'music',
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
            'event_published' => 'green',
            'event_unpublished', 'event_archived' => 'gray',
            'event_restored' => 'blue',
            'guest_created', 'guest_confirmed', 'menu_item_created' => 'green',
            'guest_updated', 'music_added', 'music_suggested', 'guest_rsvp_changed' => 'yellow',
            'invitation_opened' => 'blue',
            'invitation_template_changed', 'invitation_template_undone' => 'indigo',
            'comment_added' => 'pink',
            'guest_deleted', 'guest_declined' => 'red',
            'menu_created' => 'orange',
            'menu_item_deleted' => 'rose',
            'photo_uploaded' => 'pink',
            'location_added' => 'cyan',
            default => 'gray',
        };
    }
}
