<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MenuItem extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'menu_items';
    
    protected $fillable = [
        'menu_id',
        'name',
        'type',
        'diet_type',
        'image_path',
        'notes'
    ];
    
    /**
     * Define a relationship to the Events model.
     *
     * @return BelongsTo
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id', 'id');
    }
    
    /**
     * Define a many-to-many relationship with the Guest model.
     * @return BelongsToMany
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'guest_menu')->withTimestamps();
    }
    
}
