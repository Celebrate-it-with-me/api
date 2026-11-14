<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One text of a transactional mail edited by the admin: (registry key, locale, field) => value. */
class MessageOverride extends Model
{
    protected $fillable = ['message_key', 'locale', 'field', 'value', 'updated_by'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
