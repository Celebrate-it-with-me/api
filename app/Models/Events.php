<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Events extends Model
{
    /** @use HasFactory<\Database\Factories\EventsFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'events';

    protected $fillable = [
      'event_name',
      'event_description',
      'start_date',
      'end_date',
      'organizer_id',
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
     * Get the user that is the organizer of this event.
     *
     * @return BelongsTo
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id', 'id');
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
     * @return HasMany
     */
    public function locations(): HasMany
    {
        return $this->hasMany(EventLocation::class, 'event_id', 'id');
    }
}
