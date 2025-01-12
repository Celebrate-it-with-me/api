<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 
 *
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
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest query()
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereAccessCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereCodeUsedTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereConfirmed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereConfirmedDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereExtraPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest wherePhoneConfirmed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest wherePhoneNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MainGuest whereUpdatedAt($value)
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
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'access_code',
        'code_used_times'
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
     *
     * @return HasMany
     */
    public function partyMembers(): HasMany
    {
        return $this->hasMany(PartyMember::class, 'main_guest_id', 'id');
    }
}
