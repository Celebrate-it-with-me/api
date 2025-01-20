<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 
 *
 * @property int $id
 * @property array $recipients
 * @property string $send_date
 * @property string $message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder query()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereSendDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsReminder whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SmsReminder extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'sms_reminders';
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'recipients',
        'message',
        'send_date',
    ];

    protected $casts = [
        'recipients' => 'array'
    ];
}
