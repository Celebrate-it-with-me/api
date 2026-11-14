<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An issued invoice is immutable (docs/plans/invoices.md): only its void status, its PDF path and the date it was
 * e-mailed can change after the first save. Mistakes and refunds are handled with a credit note, never by editing.
 */
class Invoice extends Model
{
    public const MUTABLE = ['status', 'voided_at', 'void_reason', 'pdf_path', 'emailed_at', 'updated_at'];

    protected $guarded = [];

    protected $casts = [
        'issuer' => 'array', 'recipient' => 'array', 'lines' => 'array', 'tax_rate' => 'float',
        'issued_at' => 'datetime', 'voided_at' => 'datetime', 'emailed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            $frozen = array_diff(array_keys($invoice->getDirty()), self::MUTABLE);
            if ($frozen !== []) {
                throw new LogicException('An issued invoice cannot be edited (' . implode(', ', $frozen) . '). Void it with a credit note.');
            }
            if ($invoice->isDirty('status') && ! ($invoice->getOriginal('status') === 'issued' && $invoice->status === 'void')) {
                throw new LogicException('An invoice can only go from issued to void.');
            }
        });
        static::deleting(fn () => throw new LogicException('An issued invoice cannot be deleted.'));
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(EventPurchase::class, 'purchase_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }
}
