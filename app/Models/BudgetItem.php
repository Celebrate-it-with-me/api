<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetItem extends Model
{
    protected $table = 'budget_items';

    /** Status of an item, from what it owes and what has been paid. */
    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const OVERDUE = 'overdue';

    protected $fillable = [
        'event_budget_id',
        'category_id',
        'title',
        'description',
        'vendor_name',
        'vendor_phone',
        'estimated_cost',
        'agreed_cost',
        'due_date',
    ];

    protected $casts = [
        'due_date' => 'date',
        'estimated_cost' => 'decimal:2',
        'agreed_cost' => 'decimal:2',
    ];

    /** What the item costs: the agreed price, or the estimate until there is one. */
    public function owed(): float
    {
        return (float) ($this->agreed_cost ?? $this->estimated_cost);
    }

    public function paid(): float
    {
        return (float) $this->payments->sum('amount');
    }

    public function remaining(): float
    {
        return max(0, round($this->owed() - $this->paid(), 2));
    }

    public function isPaid(): bool
    {
        return $this->owed() > 0 ? $this->paid() >= $this->owed() : $this->payments->isNotEmpty();
    }

    public function status(): string
    {
        if ($this->isPaid()) {
            return self::PAID;
        }
        if ($this->due_date && $this->due_date->endOfDay()->isPast()) {
            return self::OVERDUE;
        }

        return $this->paid() > 0 ? self::PARTIAL : self::UNPAID;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BudgetPayment::class, 'budget_item_id')->orderBy('paid_on')->orderBy('id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(EventBudget::class, 'event_budget_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'category_id');
    }

    public function budgetItemReminders(): HasMany
    {
        return $this->hasMany(BudgetItemReminder::class);
    }
}
