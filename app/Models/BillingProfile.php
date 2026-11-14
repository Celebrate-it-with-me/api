<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The buyer's billing data; copied onto each invoice when it is issued. */
class BillingProfile extends Model
{
    protected $fillable = ['user_id', 'name', 'address', 'city', 'state', 'postal_code', 'country', 'tax_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
