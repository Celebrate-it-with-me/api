<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetItem extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'budget_items';
    
    protected $fillable = [
        'event_budget_id',
        'category_id',
        'title',
        'description',
        'estimated_cost',
        'actual_cost',
        'is_paid',
        'due_date'
    ];
    
    protected $casts = [
        'due_date' => 'datetime',
        'is_paid' => 'boolean',
        'estimated_cost' => 'float',
        'actual_cost' => 'float',
    ];
    
    /**
     * Define a relationship to the EventBudget model.
     *
     * @return BelongsTo
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(EventBudget::class, 'event_budget_id');
    }
    
    /**
     * Define a relationship to the BudgetCategory model.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'category_id');
    }
    
    /**
     * Retrieve the reminders associated with a budget item.
     *
     * @return HasMany|BudgetItem
     */
    public function budgetItemReminders(): HasMany|BudgetItem
    {
        return $this->hasMany(BudgetItemReminder::class);
    }
}
