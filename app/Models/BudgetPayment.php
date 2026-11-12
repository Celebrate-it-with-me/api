<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment towards a budget item (an advance, an installment, the balance). */
class BudgetPayment extends Model
{
    protected $table = 'budget_payments';

    protected $fillable = ['budget_item_id', 'amount', 'paid_on', 'note', 'padrino_id'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
