<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PadrinoCommitment extends Model
{
    protected $table = 'padrino_commitments';

    protected $fillable = ['padrino_id', 'budget_item_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function padrino(): BelongsTo
    {
        return $this->belongsTo(Padrino::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
