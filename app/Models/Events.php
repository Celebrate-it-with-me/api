<?php

namespace App\Models;

use Database\Factories\EventsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Events extends Model
{
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
        'custom_url_slug',
        'visibility'
    ];

    protected $dates = ['deleted_at'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    /**
     * Get the organizer associated with the event.
     *
     * @return HasMany
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(EventUserRole::class, 'event_id');
    }

    /**
     * Get the main guest associated with the event.
     *
     * @return HasMany
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'event_id', 'id');
    }

    /**
     * Relation with event feature.
     * @return HasOne
     */
    public function eventFeature(): HasOne
    {
        return $this->hasOne(EventFeature::class, 'event_id', 'id');
    }


    /**
     * Define a one-to-one relationship with the SaveTheDate model.
     *
     * @return HasOne
     */
    public function saveTheDate(): HasOne
    {
        return $this->hasOne(SaveTheDate::class, 'event_id', 'id');
    }

    /**
     * Define a one-to-one relationship with the Rsvp model.
     *
     * @return HasOne
     */
    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class, 'event_id', 'id');
    }

    /**
     * Get the suggested music for the event.
     *
     * @return HasMany
     */
    public function musicSuggestions(): HasMany
    {
        return $this->hasMany(SuggestedMusic::class, 'event_id', 'id');
    }

    /**
     * Get the suggested music configuration for the event.
     *
     * @return HasOne
     */
    public function suggestedMusicConfig(): HasOne
    {
        return $this->hasOne(SuggestedMusicConfig::class, 'event_id', 'id');
    }

    /**
     * Get the background music associated with the event.
     *
     * @return HasOne
     */
    public function backgroundMusic(): HasOne
    {
        return $this->hasOne(BackgroundMusic::class, 'event_id', 'id');
    }

    /**
     * Get the comments associated with the event.
     *
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class, 'event_id', 'id');
    }

    /**
     * Get the event configuration comment associated with the event.
     *
     * @return HasOne
     */
    public function eventConfigComment() : HasOne
    {
        return $this->hasOne(EventConfigComment::class, 'event_id', 'id');
    }

    /**
     * Get the sweet memories configuration for the event.
     *
     * @return HasOne
     */
    public function sweetMemoriesConfig(): HasOne
    {
        return $this->hasOne(SweetMemoriesConfig::class, 'event_id', 'id');
    }

    public function sweetMemoriesImages(): HasMany
    {
        return $this->hasMany(SweetMemoriesImage::class, 'event_id', 'id');
    }

    /**
     * Get the locations associated with the event.
     *
     * @return HasOne
     */
    public function locations(): HasOne
    {
        return $this->hasOne(EventLocation::class, 'event_id', 'id');
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
     *
     * @return BelongsTo
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class);
    }

    /**
     * Get the event type associated with the event.
     *
     * @return BelongsTo
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id', 'id');
    }

    /**
     * Get the activities associated with the event.
     *
     * @return HasMany
     */
    public function activities(): HasMany
    {
        return $this->hasMany(EventActivity::class, 'event_id', 'id');
    }

    /**
     * Get the event collaboration invites associated with the event.
     *
     * @return HasMany
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
    public function budget(): HasOne
    {
        return $this->hasOne(EventBudget::class, 'event_id', 'id');
    }

    /**
     * Get the theme associated with the event.
     *
     * @return HasOne|Events
     */
    public function theme(): HasOne|Events
    {
        return $this->hasOne(EventTheme::class, 'event_theme_id', 'id');
    }

    /**
     * @return array
     */
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
                    'secondary' => '#7b2cbf'
                ],
                'typography' => [
                    'primary_font' => 'Inter',
                    'secondary_font' => 'Inter'
                ]
            ],
            'sections' => []
        ];
    }

    /**
     * Calculates the progress of the setup based on active and completed features.
     *
     * @return float The setup progress as a proportion (0.0 to 1.0), rounded to two decimal places.
     */
    public function calculateSetupProgress(): float
    {
        $eventFeatures = $this->eventFeature;

        if (!$eventFeatures) {
            return 0.0;
        }

        $activesFeatures = $this->getActiveFeatures($eventFeatures);

        if (empty($activesFeatures)) {
            return 0.0;
        }

        $totalWeight = 0;
        $completedWeight = 0;

        foreach ($activesFeatures as $feature => $weight) {
            $totalWeight += $weight;

            if ($this->isFeatureCompleted($feature)) {
                $completedWeight += $weight;
                $checkFeaturesCompleted[$feature] = $weight;
            }
        }

        return $totalWeight > 0 ? round($completedWeight / $totalWeight, 2) : 0.0;
    }

    /**
     * Retrieves the active features from the provided event features based on predefined weights.
     *
     * @param EventFeature $eventFeatures An object containing the event features and their activation statuses.
     * @return array An associative array of active features with their corresponding weights.
     */
    private function getActiveFeatures(EventFeature $eventFeatures): array
    {
        $featureWeights = [
            'save_the_date' => 0.10,
            'rsvp' => 0.20,
            'menu' => 0.15,
            'sweet_memories' => 0.10,
            'music' => 0.08,
            'background_music' => 0.07,
            'event_comments' => 0.05,
            'seats_accommodation' => 0.10,
            'budget' => 0.10,
            'location' => 0.05
        ];

        return array_filter($featureWeights, function ($feature) use ($eventFeatures) {
            return $eventFeatures->$feature;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Determines if a specific feature is completed based on the given feature type.
     *
     * @param string $feature The feature to check for completion.
     * @return bool True if the feature is completed, false otherwise.
     */
    private function isFeatureCompleted(string $feature): bool
    {
        return match ($feature) {
            'save_the_date' => $this->saveTheDate !== null &&
                !empty($this->saveTheDate->message),
            'rsvp' => $this->isRsvpCompleted(),
            'menu' => $this->menus()->count() > 0 &&
                $this->menus()->first()?->menuItems()->count() > 0,
            'sweet_memories' => $this->sweetMemoriesConfig !== null &&
                $this->sweetMemoriesImages()->count() > 0,
            'music' => $this->suggestedMusicConfig !== null &&
                $this->musicSuggestions()->count() > 0,
            'background_music' => $this->backgroundMusic !== null &&
                !empty($this->backgroundMusic->playlist_url),
            'event_comments' => $this->eventConfigComment !== null,
            'seats_accommodation' => $this->locations !== null &&
                !empty($this->locations->capacity),
            'budget' => $this->budget !== null &&
                $this->budget->items()->count() > 0,
            'location' => $this->locations !== null &&
                !empty($this->locations->address),
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
     * @param int $totalGuestCount The total number of guests expected for the event.
     * @param int $confirmedGuests The number of guests who have confirmed their attendance.
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
