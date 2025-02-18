<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class GuestCompanion extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'main_guest_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'confirmed',
        'confirmed_date',
    ];
    
    /**
     * Define a relationship to the MainGuest model.
     *
     * @return BelongsTo
     */
    public function mainGuest(): BelongsTo
    {
        return $this->belongsTo(MainGuest::class, 'main_guest_id', 'id');
    }
}
