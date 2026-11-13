<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable like the invoice it cancels: only the PDF path can be filled in afterwards. */
class CreditNote extends Model
{
    protected $guarded = [];

    protected $casts = ['issued_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (CreditNote $note) {
            $frozen = array_diff(array_keys($note->getDirty()), ['pdf_path', 'updated_at']);
            if ($frozen !== []) {
                throw new LogicException('A credit note cannot be edited (' . implode(', ', $frozen) . ').');
            }
        });
        static::deleting(fn () => throw new LogicException('A credit note cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(EventPurchase::class, 'purchase_id');
    }
}
