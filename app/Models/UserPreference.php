<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $table = 'user_preferences';
    
    protected $fillable = [
        'user_id',
        'language',
        'timezone',
        'visual_theme',
        'date_format',
        
        'notify_by_email',
        'notify_by_sms',
        'smart_tips',
    ];
    
    /**
     * Define an inverse one-to-one or many relationship with the User model.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
}
