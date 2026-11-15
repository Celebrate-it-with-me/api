<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $main_guest_id
 * @property string $name
 * @property string $confirmed
 * @property string|null $confirmed_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MainGuest|null $mainGuest
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember query()
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereConfirmed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereConfirmedDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereMainGuestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PartyMember whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PartyMember extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'party_members';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'main_guest_id',
        'name',
        'confirmed',
    ];

    /**
     * Get the main guest associated with the model.
     */
    public function mainGuest(): BelongsTo
    {
        return $this->belongsTo(MainGuest::class, 'main_guest_id', 'id');
    }
}
