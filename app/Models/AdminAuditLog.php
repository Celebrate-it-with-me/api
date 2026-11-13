<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing staff did (who, to whom, when, why). Append-only: the application never changes or deletes a row;
 * only `admin:audit-prune` removes old ones, at the query level.
 */
class AdminAuditLog extends Model
{
    protected $table = 'admin_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The audit log cannot be deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id')->withTrashed();
    }
}
