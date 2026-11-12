<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvitationTemplate extends Model
{
    public const TIER_FREE = 'free';

    public const TIER_PREMIUM = 'premium';

    protected $fillable = [
        'slug',
        'name',
        'event_type_id',
        'tier',
        'preview_image',
        'default_sections',
        'theme_defaults',
        'is_active',
    ];

    protected $casts = [
        'default_sections' => 'array',
        'theme_defaults' => 'array',
        'is_active' => 'boolean',
    ];

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'template_id');
    }
}
