<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DressCodeImage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dress_code_id',
        'image_path',
        'original_name',
        'file_size',
        'mime_type',
        'created_at',
    ];

    protected static function booted()
    {
        static::creating(function ($image) {
            $image->created_at = $image->created_at ?: now();
        });
    }

    public function dressCode(): BelongsTo
    {
        return $this->belongsTo(DressCode::class, 'dress_code_id');
    }
}
