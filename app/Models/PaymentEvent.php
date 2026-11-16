<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One webhook delivered by the payment gateway: ids and outcome only, never the body. */
class PaymentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['received_at' => 'datetime', 'processed_at' => 'datetime'];
}
