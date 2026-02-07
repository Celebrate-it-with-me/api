<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCategory extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'budget_categories';

    protected $fillable = ['name', 'slug', 'description', 'is_default'];
    
    /**
     *
     */
    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }
    
}
