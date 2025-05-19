<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacePhoto extends Model
{
    protected $table = 'place_photos';
    
    protected $fillable = [
        'place_id',
        'photo_reference',
        'path',
        'source',
    ];
}
