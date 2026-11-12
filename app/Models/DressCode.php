<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DressCode extends Model
{
    /** The dress code types offered to organizers of a quince or a wedding. */
    public const TYPES = ['black-tie', 'formal', 'semi-formal', 'cocktail', 'smart-casual', 'thematic'];

    /** Pictures per dress code (uploads and photo bank picks together). */
    public const MAX_IMAGES = 6;

    /** Colors per palette. */
    public const MAX_COLORS = 8;

    protected $fillable = [
        'event_id',
        'dress_code_type',
        'description',
        'reserved_colors',
        'suggested_colors',
    ];

    protected $casts = [
        'reserved_colors' => 'array',
        'suggested_colors' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function dressCodeImages(): HasMany
    {
        return $this->hasMany(DressCodeImage::class, 'dress_code_id');
    }
}
