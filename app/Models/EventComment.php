<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventComment extends Model
{
    /** @use HasFactory<\Database\Factories\EventsFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'event_comments';

    protected $fillable = [
        'event_id',
        'created_by_class',
        'created_by_id',
        'comment',
        'is_approved'
    ];
    
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];
  
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
    
}
