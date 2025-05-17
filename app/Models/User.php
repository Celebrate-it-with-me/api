<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 *
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property mixed $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static Builder|User newModelQuery()
 * @method static Builder|User newQuery()
 * @method static Builder|User query()
 * @method static Builder|User whereCreatedAt($value)
 * @method static Builder|User whereEmail($value)
 * @method static Builder|User whereEmailVerifiedAt($value)
 * @method static Builder|User whereId($value)
 * @method static Builder|User whereName($value)
 * @method static Builder|User wherePassword($value)
 * @method static Builder|User whereRememberToken($value)
 * @method static Builder|User whereUpdatedAt($value)
 * @mixin Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
    
    protected $appends = [
      'avatar_url'
    ];
    
    /**
     * Relation with user login sessions.
     * @return HasMany
     */
    public function userLoginSessions(): HasMany
    {
        return $this->hasMany(UserLoginSession::class, 'user_id', 'id' );
    }
    
    /**
     * Retrieves the latest user login session where the last login time is not null.
     *
     * @return HasOne
     */
    public function lastLoginSession(): HasOne
    {
        return $this->hasOne(UserLoginSession::class, 'user_id', 'id' )
            ->whereNotNull('logout_time')
            ->latest('id');
    }
    
    /**
     * Relation with user active event.
     * @property-read Events|null $activeEvent
     * @method static Builder|User whereActiveEvent($value)
     * @mixin Eloquent
     */
    public function activeEvent(): HasOne
    {
        return $this->hasOne(Events::class, 'organizer_id', 'id');
    }
    
    /**
     * Getting the user avatar URL.
     * @property string|null $avatar
     * @method string userAvatarAttribute()
     * @mixin Eloquent
     */
    public function getAvatarUrlAttribute(): string
    {
        return $this->avatar
            ? asset('storage/' . $this->avatar)
            : '';
    }
    
    /**
     * Relation with user preferences.
     * @return HasOne
     * @property-read UserPreference|null $preferences
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class, 'user_id', 'id');
    }
    
    /**
     * Relation with user events.
     *
     * @return HasMany
     */
    public function organizedEvents(): HasMany
    {
        return $this->hasMany(Events::class, 'organizer_id', 'id');
    }
}
