<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A padrino (godparent/sponsor) of the event: who they are, what they promised to cover and what they paid. */
class Padrino extends Model
{
    public const STATUSES = ['invited', 'accepted'];

    protected $table = 'padrinos';

    protected $fillable = ['event_id', 'guest_id', 'name', 'phone', 'relationship', 'status', 'public_thanks', 'thanks_text', 'sort_order'];

    protected $casts = ['public_thanks' => 'boolean'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(PadrinoCommitment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BudgetPayment::class, 'padrino_id');
    }
}
