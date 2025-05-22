<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class EventUserRole extends Model
{
    /** @use HasFactory<\Database\Factories\EventUserRoleFactory> */
    use HasFactory;
    
    protected $table = 'event_user_roles';
    
    protected $fillable = [
        'event_id',
        'user_id',
        'role_id',
    ];
    
    /**
     * Get the event associated with this user role.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Get the event associated with this user role.
     *
     * @return BelongsTo
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class);
    }
    
    /**
     * Get the role associated with this user role.
     *
     * @return BelongsTo
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
