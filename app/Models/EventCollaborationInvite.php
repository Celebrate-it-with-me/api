<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventCollaborationInvite extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    
    protected $table = 'event_collaboration_invites';
    
    protected $fillable = [
        'event_id',
        'email',
        'role',
        'token',
        'status',
        'invited_by_user_id',
        'expires_at',
    ];
    
    protected $casts = [
        'expires_at' => 'datetime',
    ];
    
    /**
     * Scope a query to only include valid records with a status of 'pending' and
     * an expiration timestamp that is either null or in the future.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeValid($query): Builder
    {
        return $query->where('status', 'pending')
            ->where(function($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
    
    /**
     * Generates a unique token string using UUID.
     *
     * @return string A unique token string.
     */
    public static function generateToken(): string
    {
        return Str::uuid()->toString();
    }
    
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
    
    /**
     * Get the user who invited this collaboration.
     *
     * @return BelongsTo
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
    
    /**
     * Marks the current instance as accepted by updating its status.
     *
     * @return void
     */
    public function markAsAccepted(): void
    {
        $this->update([
            'status' => 'accepted',
        ]);
    }
    
    /**
     * Determines if the current instance is expired.
     *
     * @return bool True if expired, otherwise false.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
