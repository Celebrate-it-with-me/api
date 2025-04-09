<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Intervention\Image\Image;

class SweetMemoriesImage extends Model
{
    /** @use HasFactory<\Database\Factories\SweetMemoriesImageFactory> */
    use HasFactory;
    
    protected $table = 'sweet_memories_images';
    
    protected $fillable = [
        'event_id',
        'image_path',
        'image_name',
        'thumbnail_path',
        'thumbnail_name'
    ];
}
