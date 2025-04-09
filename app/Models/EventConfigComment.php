<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventConfigComment extends Model
{
    /** @use HasFactory<\Database\Factories\EventsFactory> */
    use HasFactory;

    protected $table = 'event_config_comment';

    protected $fillable = [
        'event_id',
        'title',
        'sub_title',
        'background_color',
        'comments_title',
        'button_color',
        'button_text',
        'max_comments',
    ];
  
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id', 'id');
    }
    
}
