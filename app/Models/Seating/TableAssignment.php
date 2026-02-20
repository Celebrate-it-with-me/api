<?php

namespace App\Models\Seating;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableAssignment extends Model
{
    protected $table = 'table_assignments';

    protected $fillable = [
        'table_id',
        'guest_id',
        'seat_number',
        'assigned_at',
        'assigned_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'assigned_by' => 'integer',
    ];
    
    
    /**
     * Get the table this assignment belongs to.
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
    
    /**
     * Get the guest assigned.
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
    
    /**
     * Get the user who made the assignment.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
    
    /**
     * Scope: Filter by table
     */
    public function scopeForTable($query, $tableId)
    {
        return $query->where('table_id', $tableId);
    }
    
    /**
     * Scope: Filter by guest
     */
    public function scopeForGuest($query, $guestId)
    {
        return $query->where('guest_id', $guestId);
    }
    
    /**
     * Scope: Recent assignments first
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('assigned_at', 'desc');
    }
    
    /**
     * Boot method to set assigned_at automatically
     */
    protected static function booted(): void
    {
        static::creating(function ($assignment) {
            if (empty($assignment->assigned_at)) {
                $assignment->assigned_at = now();
            }
        });
    }
}
