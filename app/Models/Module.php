<?php

namespace App\Models;

use App\Http\Services\AppServices\EventModules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One entry of the module catalog (see the `create_modules_tables` migration). */
class Module extends Model
{
    /** Sections of the menu, in the order they are shown. */
    public const SECTIONS = ['main', 'guests', 'organization', 'extras'];

    protected $fillable = ['key', 'section', 'sort_order', 'is_enabled'];

    /** A change here changes what every event's menu shows: drop the cached module reads (see EventModules). */
    protected static function booted(): void
    {
        static::saved(fn () => app(EventModules::class)->invalidate());
        static::deleted(fn () => app(EventModules::class)->invalidate());
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** Plans that include this module. */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(EventPlan::class, 'module_plan', 'module_id', 'event_plan_id');
    }
}
