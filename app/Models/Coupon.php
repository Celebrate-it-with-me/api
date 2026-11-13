<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = ['code', 'kind', 'value', 'is_founder', 'max_uses', 'times_used', 'expires_at', 'is_active', 'notes'];

    protected $casts = ['is_founder' => 'boolean', 'is_active' => 'boolean', 'expires_at' => 'datetime'];

    public function isUsable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->times_used < $this->max_uses);
    }

    /** Price after applying the code to a list price (never negative). */
    public function apply(int $listCents): int
    {
        return match ($this->kind) {
            'fixed_price' => min($listCents, $this->value),
            'percent_off' => max(0, (int) round($listCents * (100 - min(100, $this->value)) / 100)),
            default => $listCents,
        };
    }
}
