<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Guest extends Model
{
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
        'tags',
    ];

    protected $casts = [
        'rsvp_status_date' => 'datetime',
    ];

    /**
     * Get the event that this guest belongs to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }

    /**
     * Get the parent guest of this guest.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'parent_id', 'id');
    }

    /**
     * Get the companions of this guest.
     */
    public function companions(): HasMany
    {
        return $this->hasMany(Guest::class, 'parent_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestRsvpLog model.
     */
    public function rsvpLogs(): HasMany
    {
        return $this->hasMany(GuestRsvpLog::class, 'guest_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestInvitation model.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(GuestInvitation::class, 'guest_id', 'id');
    }

    /**
     * Define a one-to-many relationship with the GuestMenu model.
     */
    public function selectedMenuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'guest_menu')->withTimestamps();
    }

    public function menuAssigned(): HasOne
    {
        return $this->hasOne(Menu::class, 'id', 'assigned_menu_id');
    }
}
