<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPlan extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;

    protected $table = 'event_plans';

    protected $fillable = [
        'name', 'description', 'max_guests', 'slug', 'has_gallery', 'has_music', 'has_custom_design',
        'has_drag_editor', 'has_ai_assistant', 'has_invitations', 'has_sms',
        'has_gift_registry', 'support_level'
    ];

    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return HasMany
     */
    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'event_plan_id', 'id');
    }

}
