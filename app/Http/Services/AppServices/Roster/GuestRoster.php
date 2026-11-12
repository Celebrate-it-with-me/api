<?php

namespace App\Http\Services\AppServices\Roster;

use App\Http\Services\AppServices\GuestLimit;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The guest list as the "Invitados" screen reads it: main guests with their companions, filtered by
 * status, with counters for the whole event (in people, not in rows of the page).
 */
class GuestRoster
{
    public const STATUSES = ['attending', 'pending', 'not-attending'];

    public function __construct(private readonly GuestLimit $limit) {}

    public function page(Events $event, ?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Guest::query()
            ->with('companions')
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->when($status && in_array($status, self::STATUSES, true), function (Builder $q) use ($status) {
                // A family shows up under a status when the main guest or any of their companions has it.
                $q->where(fn (Builder $inner) => $inner
                    ->where('rsvp_status', $status)
                    ->orWhereHas('companions', fn (Builder $c) => $c->where('rsvp_status', $status)));
            })
            ->when($search, function (Builder $q, string $term) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))) . '%';
                $q->where(fn (Builder $inner) => $inner
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
                    ->orWhereHas('companions', fn (Builder $c) => $c->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->orderBy('name')
            ->orderBy('id');

        return $query->paginate(min(max($perPage, 1), 100));
    }

    /**
     * People by status for the whole event, plus the number of families (main guests) and the plan limit.
     *
     * @return array{all: int, attending: int, pending: int, notAttending: int, families: int, limit: ?int}
     */
    public function counts(Events $event): array
    {
        $rows = Guest::query()->where('event_id', $event->id)
            ->selectRaw('rsvp_status, COUNT(*) as total')->groupBy('rsvp_status')->pluck('total', 'rsvp_status');

        $by = fn (string $status) => (int) ($rows[$status] ?? 0);

        return [
            'all' => (int) $rows->sum(),
            'attending' => $by('attending'),
            'pending' => $by('pending'),
            'notAttending' => $by('not-attending'),
            'families' => Guest::query()->where('event_id', $event->id)->whereNull('parent_id')->count(),
            'limit' => $this->limit->limitFor($event),
        ];
    }

    /** The row the screen shows. @return array<string, mixed> */
    public function row(Events $event, Guest $guest): array
    {
        $link = $guest->code && $event->custom_url_slug
            ? rtrim((string) config('app.frontend_app.url'), '/') . "/i/{$event->custom_url_slug}/{$guest->code}"
            : null;

        return [
            'id' => $guest->id,
            'name' => $guest->name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'gender' => $guest->gender,
            'status' => $guest->rsvp_status,
            'statusDate' => $guest->rsvp_status_date?->toIso8601String(),
            'notes' => $guest->notes,
            'code' => $guest->code,
            'link' => $link,
            'companions' => $guest->companions->map(fn (Guest $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->rsvp_status,
                'notes' => $c->notes,
            ])->values()->all(),
        ];
    }

    /** Answers (statistics) change: the cached RSVP stats of the event must be recomputed. */
    public function forgetStats(Events $event): void
    {
        Cache::forget("event.{$event->id}.rsvp.stats");
        Cache::forget("event.{$event->id}.rsvp.summary");
    }
}
