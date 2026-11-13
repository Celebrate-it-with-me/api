<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SweetMemoriesImage extends Model
{
    /** @use HasFactory<\Database\Factories\SweetMemoriesImageFactory> */
    use HasFactory;

    protected $table = 'sweet_memories_images';

    protected $fillable = [
        'event_id',
        'image_path',
        'image_name',
        'image_original_name',
        'image_size',
        'thumbnail_path',
        'thumbnail_name',
        'title',
        'description',
        'year',
        'visible',
        'order',
        'hidden_at',
        'hidden_by',
    ];

    protected $casts = [
        'hidden_at' => 'datetime',
    ];

    /** Not hidden by staff moderation (admin A6). Every guest/organizer output must use it. */
    public function scopeNotHidden($query)
    {
        return $query->whereNull($query->qualifyColumn('hidden_at'));
    }

    /**
     * Defines the relationship between the current model and the Events model.
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
