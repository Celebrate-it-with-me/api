<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventBudget extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'event_budgets';

    protected $fillable = ['event_id', 'budget_cap'];
    
    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
    
    /**
     * Define a one-to-many relationship with the BudgetItem model.
     *
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }
    
}
