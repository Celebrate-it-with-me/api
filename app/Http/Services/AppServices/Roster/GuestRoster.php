<?php

namespace App\Http\Services\AppServices\Roster;

use App\Http\Services\AppServices\GuestLimit;
use App\Http\Services\WhatsApp\DeliveryTracker;
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

    public function __construct(private readonly GuestLimit $limit, private readonly DeliveryTracker $delivery) {}

    /** @param list<int>|null $onlyIds restrict to these families (the send-state chips) */
    public function page(Events $event, ?string $status, ?string $search, int $perPage, ?array $onlyIds = null): LengthAwarePaginator
    {
        return $this->query($event, $status, $search, $onlyIds)->paginate(min(max($perPage, 1), 100));
    }

    /** Send state of every family of the event, read once per request. @return array<int, array<string, mixed>> */
    public function deliveryMap(Events $event): array
    {
        return $this->delivery->forEvent($event);
    }

    /** @param array<int, array<string, mixed>> $map @return array<string, int> */
    public function deliveryCounts(array $map): array
    {
        return $this->delivery->counts($map);
    }

    /** @param array<int, array<string, mixed>> $map @return list<int> */
    public function deliveryIds(array $map, string $filter): array
    {
        return $this->delivery->idsFor($map, $filter);
    }

    /** Main guests (families) of the event for a tab and a search term, in the order the screen shows them. */
    public function query(Events $event, ?string $status, ?string $search, ?array $onlyIds = null): Builder
    {
        return Guest::query()
            ->with('companions')
            ->where('event_id', $event->id)
            ->whereNull('parent_id')
            ->when($onlyIds !== null, fn (Builder $q) => $q->whereIn('id', $onlyIds))
            ->when($status === 'pending', function (Builder $q) {
                // "Pending" is the main guest who has not answered (the same rule the send uses); companions
                // without an answer show on the row.
                $q->where('rsvp_status', 'pending');
            })
            ->when($status && $status !== 'pending' && in_array($status, self::STATUSES, true), function (Builder $q) use ($status) {
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

    /** The row the screen shows. @param array<string, mixed>|null $delivery the family's send state; read here when not given @return array<string, mixed> */
    public function row(Events $event, Guest $guest, ?array $delivery = null): array
    {
        $delivery ??= $this->delivery->forGuests($event, collect([$guest->parent_id ? $guest->parent : $guest]))[$guest->parent_id ?: $guest->id];

        $link = $guest->code && $event->custom_url_slug
            ? rtrim((string) config('app.frontend_app.url'), '/') . "/i/{$event->custom_url_slug}/{$guest->code}"
            : null;

        return [
            'id' => $guest->id,
            'name' => $guest->name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'whatsappOptedOut' => $guest->whatsapp_opted_out_at !== null,
            'gender' => $guest->gender,
            'status' => $guest->rsvp_status,
            'statusDate' => $guest->rsvp_status_date?->toIso8601String(),
            'notes' => $guest->notes,
            // The guest's own message from the RSVP (plain text); `notes` above is the organizer's.
            'rsvpNote' => $guest->rsvp_note,
            'rsvpNoteAt' => $guest->rsvp_note_at?->toIso8601String(),
            // The invitation language: what the organizer set (null = the event's) and the one the guest will see.
            'locale' => $guest->locale,
            'effectiveLocale' => $guest->locale ?: ($event->locale ?: 'es'),
            'code' => $guest->code,
            'link' => $link,
            // Where the family stands with the invitation (WhatsApp message or marked as shared by hand).
            'delivery' => $delivery,
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
