<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A category of one event's budget: copied from the catalog when the budget is created, then the organizer's own. */
class BudgetCategory extends Model
{
    protected $table = 'budget_categories';

    protected $fillable = ['event_budget_id', 'name', 'slug', 'description', 'sort_order', 'is_default'];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(EventBudget::class, 'event_budget_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'category_id');
    }
}
