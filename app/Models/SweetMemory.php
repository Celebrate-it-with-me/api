<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SweetMemory extends Model
{
    /** @use HasFactory<\Database\Factories\SweetMemoriesImageFactory> */
    use HasFactory;

    protected $table = 'sweet_memories';

    protected $fillable = [
        'event_id',
        'title',
        'description',
        'year',
        'visible',
        'image_path',
    ];
    
    protected $casts = [
        'visible' => 'boolean',
    ];

    /**
     * Defines the relationship between the current model and the Events model.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
}
