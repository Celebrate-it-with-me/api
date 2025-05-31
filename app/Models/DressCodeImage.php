<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DressCodeImage extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'dress_code_images';

    protected $fillable = [
        'dress_code_id',
        'image_path',
    ];
    
    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return HasMany
     */
    public function dressCode(): BelongsTo
    {
        return $this->belongsTo(DressCode::class, 'dress_code_id');
    }
    
}
