<?php

namespace App\Models;

use App\Models\Seating\TableAssignment;
use App\Models\WhatsApp\OptOut;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use HasFactory, SoftDeletes;

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
        'gender',
        'locale',
    ];

    protected $casts = [
        'rsvp_status_date' => 'datetime',
        'rsvp_note_at' => 'datetime',
        'whatsapp_opted_out_at' => 'datetime',
        'tags' => 'array'
    ];

    /**
     * The WhatsApp opt-out lives with the phone number (whatsapp_opt_outs); the column is kept in step with it whenever
     * a guest is created or gets a new phone, so a recreated guest or a changed number never gets messaged again.
     */
    protected static function booted(): void
    {
        static::saving(function (Guest $guest) {
            if (! $guest->exists || $guest->isDirty('phone')) {
                $opted = OptOut::has($guest->phone);
                if ($opted && $guest->whatsapp_opted_out_at === null) {
                    $guest->whatsapp_opted_out_at = now();
                } elseif (! $opted && $guest->exists) {
                    $guest->whatsapp_opted_out_at = null;
                }
            }
        });
    }

    /** True when this guest must not get WhatsApp messages: opted out on the row or on the phone number. */
    public function whatsappBlocked(): bool
    {
        return $this->whatsapp_opted_out_at !== null || OptOut::has($this->phone);
    }

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
    
    public function tableAssignment(): HasOne|Guest
    {
        return $this->hasOne(TableAssignment::class, 'guest_id');
    }
}
