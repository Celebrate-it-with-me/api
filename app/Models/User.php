<?php

namespace App\Models;

use App\Support\Mail\MailLocale;
use Eloquent;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property mixed $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
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
 *
 * @mixin Eloquent
 */
class User extends Authenticatable implements HasLocalePreference
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'has_password' => true,
    ];

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
        // Never serialized: whoever reads this user over the API (including a support session) must not get the TOTP secret.
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'suspended_at' => 'datetime',
        'admin_suspended_at' => 'datetime',
        'admin_last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'anonymized_at' => 'datetime',
        'account_deleted_at' => 'datetime',
        'has_password' => 'boolean',
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    protected $appends = [
        'avatar_url',
        'emailVerified',
    ];

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** Whether the address was verified (the app shows a notice while it is not). */
    public function getEmailVerifiedAttribute(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** Two-factor enrolment of a staff member (admin panel); null when she never started it. */
    public function adminTwoFactor(): HasOne
    {
        return $this->hasOne(AdminTwoFactor::class);
    }

    /** A pending change of sign-in email, waiting for the new address to confirm. */
    public function adminEmailChange(): HasOne
    {
        return $this->hasOne(AdminEmailChange::class);
    }

    public function hasAdminTwoFactor(): bool
    {
        return (bool) $this->adminTwoFactor?->isActive();
    }

    /**
     * Relation with user login sessions.
     */
    public function userLoginSessions(): HasMany
    {
        return $this->hasMany(UserLoginSession::class, 'user_id', 'id');
    }

    /**
     * Retrieves the latest user login session where the last login time is not null.
     */
    public function lastLoginSession(): HasOne
    {
        return $this->hasOne(UserLoginSession::class, 'user_id', 'id')
            ->whereNotNull('logout_time')
            ->latest('id');
    }

    /**
     * Relation with user active event.
     *
     * @property-read Events|null $activeEvent
     *
     * @method static Builder|User whereActiveEvent($value)
     *
     * @mixin Eloquent
     */
    public function activeEvent(): HasOne
    {
        return $this->hasOne(Events::class, 'id', 'last_active_event_id');
    }

    /**
     * Relation with user social accounts.
     */
    public function socialAccounts(): Builder|User|HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Getting the user avatar URL.
     *
     * @property string|null $avatar
     *
     * @method string userAvatarAttribute()
     *
     * @mixin Eloquent
     */
    public function getAvatarUrlAttribute(): string
    {
        return $this->avatar
            ? asset('storage/' . $this->avatar)
            : '';
    }

    /** Language of the mails and notifications she receives (es/en). */
    public function preferredLocale(): string
    {
        return MailLocale::forUser($this);
    }

    /**
     * Relation with user preferences.
     *
     * @property-read UserPreference|null $preferences
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class, 'user_id', 'id');
    }

    /**
     * Retrieves all events that the user has access to.
     */
    public function accessibleEvents(): Collection
    {
        return Events::query()
            ->withResourceData()
            ->whereHas('userRoles', function ($query) {
                $query->where('user_id', $this->id);
            })->get();
    }

    public function ownedEvents(): Collection
    {
        return Events::query()
            ->whereHas('userRoles', function ($query) {
                $query->where('user_id', $this->id)
                    ->whereHas('role', function ($q) {
                        $q->where('name', 'owner');
                    });
            })->get();
    }

    /**
     * Check if the user has a specific role for the given event.
     *
     * @param  Events  $event  The event instance.
     * @param  string  $roleSlug  The slug of the role to check.
     * @return bool True if the user has the specified role for the event, otherwise false.
     */
    public function hasEventRole(Events $event, ?string $roleSlug = null): bool
    {
        return EventUserRole::query()
            ->where('event_id', $event->id)
            ->where('user_id', $this->id)
            ->when($roleSlug, function ($query, $roleSlug) {
                return $query->whereHas('role', function ($query) use ($roleSlug) {
                    $query->where('name', $roleSlug);
                });
            })
            ->exists();
    }

    /**
     * Check if the user has a specific permission for the given event.
     */
    public function hasEventPermission(Events $event, string $permissionSlug): bool
    {
        $eventUserRole = EventUserRole::query()
            ->where('event_id', $event->id)
            ->where('user_id', $this->id)
            ->first();
        if (! $eventUserRole) {
            return false;
        }

        return $eventUserRole->role
            ->permissions()
            ->where('name', $permissionSlug)
            ->exists();
    }

    public function eventRoles(): HasMany
    {
        return $this->hasMany(EventUserRole::class, 'user_id', 'id');
    }

    /**
     * Retrieves the permissions associated with a specific event for the current user.
     *
     * @param  Events  $event  The event instance for which to retrieve permissions.
     * @return array An array containing the names of the permissions.
     */
    public function getEventPermissions(Events $event): array
    {
        $eventUserRole = $this->eventRoles()
            ->where('event_id', $event->id)
            ->with('role.permissions')
            ->first();

        if (! $eventUserRole || ! $eventUserRole->role) {
            return [];
        }

        return $eventUserRole->role->permissions->pluck('name')->toArray();
    }

    /**
     * Retrieve the reminders associated with a user.
     */
    public function budgetItemReminders(): HasMany
    {
        return $this->hasMany(BudgetItemReminder::class, 'user_id', 'id');
    }
}
