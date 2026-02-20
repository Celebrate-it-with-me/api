<?php

namespace App\Models\Seating;

use App\Models\Events;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    protected $table = 'tables';

    protected $fillable = [
        'event_id',
        'name',
        'capacity',
        'type',
        'priority',
        'reserved_for',
        'location',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'priority' => 'integer',
    ];
    
    /**
     * Get the event that owns the table.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
    
    /**
     * Get all assignments for this table.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TableAssignment::class);
    }
    
    /**
     * Get assigned guests through assignments.
     */
    public function assignedGuests()
    {
        return $this->hasManyThrough(
            Guest::class,
            TableAssignment::class,
            'table_id',      // Foreign key on table_assignments table
            'id',            // Foreign key on guests table
            'id',            // Local key on tables table
            'guest_id'       // Local key on table_assignments table
        )->with('tableAssignment');
    }
    
    /**
     * Scope: Filter by event
     */
    public function scopeForEvent($query, $eventId)
    {
        return $query->where('event_id', $eventId);
    }
    
    /**
     * Scope: Filter by type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }
    
    /**
     * Scope: Order by priority
     */
    public function scopeByPriority($query, $direction = 'desc')
    {
        return $query->orderBy('priority', $direction);
    }
    
    /**
     * Scope: With assigned guests count
     */
    public function scopeWithAssignedCount($query)
    {
        return $query->withCount('assignments as assigned_guests_count');
    }
    
    /**
     * Check if table has available seats
     */
    public function hasAvailableSeats(): bool
    {
        return $this->assignments()->count() < $this->capacity;
    }
    
    /**
     * Get number of available seats
     */
    public function getAvailableSeatsAttribute(): int
    {
        return $this->capacity - $this->assignments()->count();
    }
    
    /**
     * Get occupancy percentage (0-100)
     */
    public function getOccupancyPercentageAttribute(): float
    {
        if ($this->capacity === 0) {
            return 0;
        }
        
        $assigned = $this->assignments()->count();
        return round(($assigned / $this->capacity) * 100, 2);
    }
    
    /**
     * Check if table is full
     */
    public function isFull(): bool
    {
        return $this->assignments()->count() >= $this->capacity;
    }
    
    /**
     * Check if guest can be assigned to this table
     */
    public function canAssignGuest(): bool
    {
        return $this->hasAvailableSeats();
    }
    
    /**
     * Get next available seat number
     */
    public function getNextSeatNumber(): string
    {
        $count = $this->assignments()->count();
        return "{$this->name} - Seat " . ($count + 1);
    }
}
