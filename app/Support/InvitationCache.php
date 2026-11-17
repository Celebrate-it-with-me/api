<?php

namespace App\Support;

use App\Models\BackgroundMusic;
use App\Models\BudgetItem;
use App\Models\DressCode;
use App\Models\DressCodeImage;
use App\Models\EventBudget;
use App\Models\EventConfigComment;
use App\Models\EventLocation;
use App\Models\EventLocationImages;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\Module;
use App\Models\Padrino;
use App\Models\PadrinoCommitment;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusicConfig;
use App\Models\SweetMemoriesImage;
use App\Models\TemplatePackage;
use App\Models\Timeline;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side cache of the part of the public invitation that is the same for every guest of an event (theme,
 * sections, event data): see InvitationService::build. What differs per guest (name, RSVP answers, companions, note) is
 * never stored here, so a cached entry cannot leak one guest's data to another.
 *
 * Invalidation is by token, not by deleting keys (a driver cannot list them): every entry key carries the global token
 * and the event's token, and changing any source model gives a fresh token, so old entries are simply never read again
 * and expire by themselves. The token lives in the same store as the entries. A short TTL is the safety net for what
 * does not go through Eloquent events (query-builder writes, a staff exception that expires with time).
 *
 * Not invalidated on purpose: the guest's RSVP (it is in the per-guest part), `Rsvp` deadline (read live) and anything
 * that only the organizer's dashboard shows.
 */
class InvitationCache
{
    private const GLOBAL = 'invitation:epoch:global';

    /**
     * Models whose change alters the invitation of one event: class => how to find the event id (null = unknown, so
     * the whole cache is renewed).
     *
     * @return array<class-string<Model>, Closure(Model): ?int>
     */
    private static function eventSources(): array
    {
        return [
            Events::class => fn (Events $m) => $m->id,
            EventLocation::class => fn (EventLocation $m) => $m->event_id,
            EventLocationImages::class => fn (EventLocationImages $m) => EventLocation::query()->whereKey($m->event_location_id ?? null)->value('event_id'),
            Timeline::class => fn (Timeline $m) => $m->event_id,
            DressCode::class => fn (DressCode $m) => $m->event_id,
            DressCodeImage::class => fn (DressCodeImage $m) => DressCode::query()->whereKey($m->dress_code_id)->value('event_id'),
            Padrino::class => fn (Padrino $m) => $m->event_id,
            PadrinoCommitment::class => fn (PadrinoCommitment $m) => Padrino::query()->whereKey($m->padrino_id)->value('event_id'),
            BudgetItem::class => fn (BudgetItem $m) => EventBudget::query()->whereKey($m->event_budget_id)->value('event_id'),
            SweetMemoriesImage::class => fn (SweetMemoriesImage $m) => $m->event_id,
            BackgroundMusic::class => fn (BackgroundMusic $m) => $m->event_id,
            SaveTheDate::class => fn (SaveTheDate $m) => $m->event_id,
            EventConfigComment::class => fn (EventConfigComment $m) => $m->event_id,
            SuggestedMusicConfig::class => fn (SuggestedMusicConfig $m) => $m->event_id,
            EventModuleOverride::class => fn (EventModuleOverride $m) => $m->event_id,
        ];
    }

    /** Models shared by every event (templates, packages, the module/plan catalog): any change renews everything. */
    private const GLOBAL_SOURCES = [
        InvitationTemplate::class,
        InvitationTemplateVersion::class,
        TemplatePackage::class,
        Module::class,
        EventPlan::class,
        EventType::class,
    ];

    /** Listens to the models above. Called once from the service provider. */
    public static function register(): void
    {
        foreach (self::eventSources() as $class => $resolve) {
            $handle = function (Model $model) use ($resolve) {
                $id = $resolve($model);
                $id === null ? self::flushAll() : self::flushEvent((int) $id);
            };
            $class::saved($handle);
            $class::deleted($handle);
            if (method_exists($class, 'restored')) {
                $class::restored($handle);
            }
        }

        foreach (self::GLOBAL_SOURCES as $class) {
            $class::saved(fn () => self::flushAll());
            $class::deleted(fn () => self::flushAll());
        }
    }

    public static function ttl(): int
    {
        return max(1, (int) config('http.invitation.cache_ttl', 300));
    }

    /**
     * The shared payload of an event for one locale, built by $build on a miss. Never caches when the event is not
     * served (the caller already resolved a published/archived event: this only stores what $build returns).
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function remember(int $eventId, string $locale, Closure $build): array
    {
        $key = self::key($eventId, $locale);

        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $value = $build();
        Cache::put($key, $value, self::ttl());

        return $value;
    }

    public static function key(int $eventId, string $locale): string
    {
        return sprintf('invitation:shared:%s:%s:%d:%s', self::token(self::GLOBAL), self::token(self::eventKey($eventId)), $eventId, $locale);
    }

    /** After the commit, so a request cannot re-read the old rows and store them under the new token. */
    public static function flushEvent(int $eventId): void
    {
        DB::afterCommit(fn () => self::bump(self::eventKey($eventId)));
    }

    public static function flushAll(): void
    {
        DB::afterCommit(fn () => self::bump(self::GLOBAL));
    }

    /** Moves an epoch. A broken cache store must not break the write that triggered it (same policy as EventModules). */
    private static function bump(string $key): void
    {
        try {
            Cache::forever($key, (string) Str::uuid());
        } catch (Throwable $e) {
            Log::warning('The invitation cache is not available: ' . $e->getMessage());
        }
    }

    private static function eventKey(int $eventId): string
    {
        return "invitation:epoch:event:{$eventId}";
    }

    /** A token always exists once it was read: if the store loses it, a new one starts (never goes back to an old value). */
    private static function token(string $key): string
    {
        $token = Cache::get($key);

        if (! is_string($token)) {
            $token = (string) Str::uuid();
            // add(): two requests racing agree on one token.
            Cache::add($key, $token);
            $token = (string) Cache::get($key, $token);
        }

        return $token;
    }
}
