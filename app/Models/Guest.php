<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Guest extends Model
{
    use HasFactory;

    protected $table = 'guests';


    protected $fillable = [
        'event_id',
        'parent_id',
        'name',
        'email',
        'phone',
        'rsvp_status',
        'rsvp_status_date',
        'assigned_menu_id',
        'code',
        'notes',
        'is_vip',
        'tags'
    ];

    protected $casts = [
        'rsvp_status_date' => 'datetime',
        'tags' => 'array'
    ];

    /**
     * Get the event that this guest belongs to.
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    /**
     * Get the parent guest of this guest.
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'parent_id', 'id');
    }

    /**
     * Get the companions of this guest.
     * @return HasMany
     */
    public function companions(): HasMany
    {
        return $this->hasMany(Guest::class, 'parent_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestRsvpLog model.
     *
     * @return HasMany
     */
    public function rsvpLogs(): HasMany
    {
        return $this->hasMany(GuestRsvpLog::class, 'guest_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestInvitation model.
     *
     * @return HasMany
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(GuestInvitation::class, 'guest_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestMenu model.
     * @return BelongsToMany
     */
    public function selectedMenuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'guest_menu')->withTimestamps();
    }

    public function menuAssigned(): HasOne
    {
        return $this->hasOne(Menu::class, 'id', 'assigned_menu_id');
    }

    public function assignedMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'assigned_menu_id');
    }
}
