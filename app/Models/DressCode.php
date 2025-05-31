<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DressCode extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'dress_code';

    protected $fillable = [
        'event_id',
        'dress_code_type',
        'description',
        'reserved_colors'
    ];
    
    protected $casts = [
        'reserved_colors' => 'array',
    ];
    
    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }
    
    public function dressCodeImages(): HasMany
    {
        return $this->hasMany(DressCodeImage::class, 'dress_code_id', 'id');
    }
    
}
