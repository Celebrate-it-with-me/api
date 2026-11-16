<?php

namespace App\Models;

use App\Http\Services\AppServices\EventModules;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPlan extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    protected $table = 'event_plans';

    /** A change here changes what every event's menu shows: drop the cached module reads (see EventModules). */
    protected static function booted(): void
    {
        static::saved(fn () => app(EventModules::class)->invalidate());
        static::deleted(fn () => app(EventModules::class)->invalidate());
    }

    protected $fillable = [
        'name', 'description', 'max_guests', 'price_cents', 'currency', 'slug', 'has_gallery', 'has_music', 'has_custom_design',
        'has_drag_editor', 'has_ai_assistant', 'has_invitations', 'has_sms',
        'has_gift_registry', 'support_level',
    ];

    /**
     * Define a one-to-many relationship with the Events model.
     *
     * @return HasMany
     */
    /** Modules this plan includes. */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_plan', 'event_plan_id', 'module_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Events::class, 'event_plan_id', 'id');
    }
}
