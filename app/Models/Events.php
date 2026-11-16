<?php

namespace App\Models;

use App\Http\Services\AppServices\EventModules;
use Database\Factories\EventsFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Events extends Model
{
    /** What the public link shows once published. */
    public const STAGE_SAVE_THE_DATE = 'save_the_date';

    public const STAGE_INVITATION = 'invitation';

    /** @use HasFactory<EventsFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'events';

    protected $fillable = [
        'event_name',
        'event_description',
        'event_type_id',
        'event_plan_id',
        'start_date',
        'end_date',
        'status',
        'published_at',
        'custom_url_slug',
        'template_id',
        'template_version_id',
        'template_config',
        'template_config_stash',
        'locale',
        'currency',
        'timezone',
    ];

    protected $dates = ['deleted_at'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'purge_after' => 'datetime',
        'template_config' => 'array',
        'template_config_stash' => 'array',
    ];

    /**
     * Get the organizer associated with the event.
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(EventUserRole::class, 'event_id');
    }

    /**
     * Get the owner/organizer of the event.
     */
    public function owner(): HasOne
    {
        return $this->hasOne(EventUserRole::class, 'event_id')
            ->whereHas('role', function ($query) {
                $query->where('name', 'owner');
            })
            ->with('user');
    }

    /**
     * Get the owner user directly.
     */
    public function getOwnerUserAttribute(): ?User
    {
        return $this->owner?->user;
    }

    /**
     * Get the owner user directly.
     */
    public function getOrganizerAttribute(): ?User
    {
        return $this->owner?->user;
    }

    /**
     * Get the owner user ID directly.
     */
    public function getOwnerUserIdAttribute(): int
    {
        return $this->owner?->user?->id ?? 0;
    }

    /**
     * Archived because its owner deleted her account (docs/plans/account-deletion-contract.md): the public invitation
     * stays readable until `purge_after`, but nothing can be answered or sent and nobody can bring it back.
     */
    public function isAccountArchived(): bool
    {
        return $this->status === 'archived' && $this->purge_after !== null;
    }

    /** Scope: events archived by an account deletion. */
    public function scopeAccountArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived')->whereNotNull('purge_after');
    }

    /**
     * The public address (`custom_url_slug`) is fixed once the event has been published, even if it goes
     * back to draft: the links `/i/{slug}/{code}` may already have been sent to guests.
     */
    public function isSlugLocked(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Invitation template this event was built from.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InvitationTemplate::class, 'template_id');
    }

    /**
     * The template version fixed when the event was first published (null while it follows the current one).
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(InvitationTemplateVersion::class, 'template_version_id');
    }

    /**
     * Get the main guest associated with the event.
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'event_id', 'id');
    }

    /**
     * Define a one-to-one relationship with the SaveTheDate model.
     */
    public function saveTheDate(): HasOne
    {
        return $this->hasOne(SaveTheDate::class, 'event_id', 'id');
    }

    /**
     * Define a one-to-one relationship with the Rsvp model.
     */
    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class, 'event_id', 'id');
    }

    /**
     * Get the suggested music for the event.
     */
    public function musicSuggestions(): HasMany
    {
        return $this->hasMany(SuggestedMusic::class, 'event_id', 'id');
    }

    /**
     * Get the suggested music configuration for the event.
     */
    public function suggestedMusicConfig(): HasOne
    {
        return $this->hasOne(SuggestedMusicConfig::class, 'event_id', 'id');
    }

    /**
     * Get the background music associated with the event.
     */
    public function backgroundMusic(): HasOne
    {
        return $this->hasOne(BackgroundMusic::class, 'event_id', 'id');
    }

    /**
     * Get the comments associated with the event.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class, 'event_id', 'id');
    }

    /**
     * Get the event configuration comment associated with the event.
     */
    public function eventConfigComment(): HasOne
    {
        return $this->hasOne(EventConfigComment::class, 'event_id', 'id');
    }

    /**
     * Get the sweet memories configuration for the event.
     */
    public function sweetMemoriesConfig(): HasOne
    {
        return $this->hasOne(SweetMemoriesConfig::class, 'event_id', 'id');
    }

    public function sweetMemoriesImages(): HasMany
    {
        // Photos hidden by staff moderation never reach guests or organizers.
        return $this->hasMany(SweetMemoriesImage::class, 'event_id', 'id')->whereNull('sweet_memories_images.hidden_at');
    }

    /**
     * Get the locations associated with the event.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(EventLocation::class, 'event_id', 'id');
    }

    /**
     * Get the main guest associated with the event.
     */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class, 'event_id', 'id');
    }

    /**
     * Get the event plan associated with the event.
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class);
    }

    /**
     * Get the event type associated with the event.
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id', 'id');
    }

    /**
     * Get the activities associated with the event.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(EventActivity::class, 'event_id', 'id');
    }

    /**
     * Get the event collaboration invites associated with the event.
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(EventCollaborationInvite::class, 'event_id', 'id');
    }

    /**
     * Get the event budget associated with the event.
     *
     * @return HasOne
     */
    public function padrinos(): HasMany
    {
        return $this->hasMany(Padrino::class, 'event_id', 'id')->orderBy('sort_order')->orderBy('id');
    }

    public function budget(): HasOne
    {
        return $this->hasOne(EventBudget::class, 'event_id', 'id');
    }

    public function dressCode(): HasOne
    {
        return $this->hasOne(DressCode::class, 'event_id', 'id');
    }

    /**
     * Get the theme associated with the event.
     */
    public function theme(): HasOne|Events
    {
        return $this->hasOne(EventTheme::class, 'event_theme_id', 'id');
    }

    public function getThemeConfigAttribute(): array
    {
        return $this->theme
            ? $this->theme->config
            : $this->getDefaultThemeConfig();
    }

    /**
     * Retrieve the default configuration for the theme, including global colors and typography settings.
     *
     * @return array The default theme configuration.
     */
    public function getDefaultThemeConfig(): array
    {
        return [
            'global' => [
                'colors' => [
                    'primary' => '#e91e63',
                    'secondary' => '#7b2cbf',
                ],
                'typography' => [
                    'primary_font' => 'Inter',
                    'secondary_font' => 'Inter',
                ],
            ],
            'sections' => [],
        ];
    }

    /**
     * Calculates the progress of the setup based on active and completed features.
     *
     * @return float The setup progress as a proportion (0.0 to 1.0), rounded to two decimal places.
     */
    public function calculateSetupProgress(): float
    {
        $activeWeights = $this->getActiveFeatures();

        if (empty($activeWeights)) {
            return 0.0;
        }

        $totalWeight = 0;
        $completedWeight = 0;

        foreach ($activeWeights as $module => $weight) {
            $totalWeight += $weight;

            if ($this->isFeatureCompleted($module)) {
                $completedWeight += $weight;
            }
        }

        return $totalWeight > 0 ? round($completedWeight / $totalWeight, 2) : 0.0;
    }

    /**
     * The weight of each module in the setup progress, by module key (see the `modules` catalog).
     * Only the modules that are active for this event count: the plan decides them (EventModules).
     *
     * @return array<string, float>
     */
    private function getActiveFeatures(): array
    {
        $featureWeights = [
            'save_the_date' => 0.10,
            'rsvp' => 0.20,
            'menus' => 0.15,
            'memories' => 0.10,
            'suggested_music' => 0.08,
            'comments' => 0.05,
            'seating' => 0.10,
            'budget' => 0.10,
            'locations' => 0.05,
        ];

        $modules = app(EventModules::class);

        return array_filter($featureWeights, fn ($weight, $key) => $modules->isActive($this, $key), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Determines if a specific feature is completed based on the given feature type.
     *
     * @param  string  $feature  The feature to check for completion.
     * @return bool True if the feature is completed, false otherwise.
     */
    private function isFeatureCompleted(string $feature): bool
    {
        return match ($feature) {
            'save_the_date' => $this->saveTheDate !== null &&
                ! empty($this->saveTheDate->message),
            'rsvp' => $this->isRsvpCompleted(),
            'menus' => $this->menus()->count() > 0 &&
                $this->menus()->first()?->menuItems()->count() > 0,
            'memories' => $this->sweetMemoriesConfig !== null &&
                $this->sweetMemoriesImages()->count() > 0,
            'suggested_music' => $this->suggestedMusicConfig !== null &&
                $this->musicSuggestions()->count() > 0,
            'comments' => $this->eventConfigComment !== null,
            'seating' => $this->locations !== null &&
                ! empty($this->locations->capacity),
            'budget' => $this->budget !== null &&
                $this->budget->items()->count() > 0,
            'locations' => $this->locations !== null &&
                ! empty($this->locations->address),
            default => false,
        };
    }

    private function isRsvpCompleted(): bool
    {
        $totalGuestCount = $this->guests()->count();

        if ($totalGuestCount === 0) {
            return false;
        }

        $confirmedGuests = $this->guests()
            ->whereIn('rsvp_status', ['attending', 'not-attending'])
            ->count();

        return $this->evaluateRsvpCompletion($totalGuestCount, $confirmedGuests);
    }

    /**
     * Evaluates whether the RSVP process can be considered complete based on the total guest count and the number of confirmed guests.
     *
     * @param  int  $totalGuestCount  The total number of guests expected for the event.
     * @param  int  $confirmedGuests  The number of guests who have confirmed their attendance.
     * @return bool Returns true if the RSVP completion criteria are met, otherwise false.
     */
    private function evaluateRsvpCompletion(int $totalGuestCount, int $confirmedGuests): bool
    {
        if ($confirmedGuests < 1) {
            return false;
        }

        $confirmationRate = $confirmedGuests / $totalGuestCount;

        if ($totalGuestCount <= 10) {
            return $confirmationRate >= 0.5;
        }

        if ($totalGuestCount <= 50) {
            return $confirmationRate >= 0.3;
        }

        return $confirmationRate >= 0.2;
    }
}
