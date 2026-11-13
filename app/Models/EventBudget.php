<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventBudget extends Model
{
    use HasFactory;

    protected $table = 'event_budgets';

    protected $fillable = ['event_id', 'budget_cap'];

    protected $casts = ['budget_cap' => 'decimal:2'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(BudgetCategory::class)->orderBy('sort_order')->orderBy('id');
    }
}
