<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string $phone_number
 * @property int $phone_confirmed
 * @property string|null $extra_phone
 * @property string $confirmed
 * @property string|null $confirmed_date
 * @property string $access_code
 * @property int $code_used_times
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PartyMember> $partyMembers
 * @property-read int|null $party_members_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static Builder|MainGuest newModelQuery()
 * @method static Builder|MainGuest newQuery()
 * @method static Builder|MainGuest query()
 * @method static Builder|MainGuest whereAccessCode($value)
 * @method static Builder|MainGuest whereCodeUsedTimes($value)
 * @method static Builder|MainGuest whereConfirmed($value)
 * @method static Builder|MainGuest whereConfirmedDate($value)
 * @method static Builder|MainGuest whereCreatedAt($value)
 * @method static Builder|MainGuest whereEmail($value)
 * @method static Builder|MainGuest whereExtraPhone($value)
 * @method static Builder|MainGuest whereFirstName($value)
 * @method static Builder|MainGuest whereId($value)
 * @method static Builder|MainGuest whereLastName($value)
 * @method static Builder|MainGuest wherePhoneConfirmed($value)
 * @method static Builder|MainGuest wherePhoneNumber($value)
 * @method static Builder|MainGuest whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class MainGuest extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'access_code',
        'code_used_times',
        'confirmed',
        'confirmed_date',
        'companion_type',
        'companion_qty',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'party_members' => 'array',
    ];

    /**
     * Get the party members associated with this model.
     */
    public function partyMembers(): HasMany
    {
        return $this->hasMany(PartyMember::class, 'main_guest_id', 'id');
    }

    /**
     * Companions relationship.
     */
    public function companions(): HasMany
    {
        return $this->hasMany(GuestCompanion::class, 'main_guest_id', 'id');
    }

    /**
     * Relation with events model
     *
     * @property int $event_id
     * @property-read Events $event
     *
     * @method static Builder|Model whereEventId($value)
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
