<?php

namespace App\Http\Services\Admin\EventActions;

use App\Models\AdminAuditLog;
use App\Models\EventActivity;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** The Registro tab: what happened in the event (activity) and, for who may see it, what staff did to it. */
class EventLogService
{
    /** Payload keys that are safe to show to staff; the rest of the payload stays out. */
    private const PAYLOAD_KEYS = ['event_name', 'name', 'title', 'count', 'status'];

    public function activity(Events $event, int $page, int $perPage): array
    {
        $p = EventActivity::where('event_id', $event->id)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $rows = $p->getCollection();

        $userType = (new User)->getMorphClass();
        $guestType = (new Guest)->getMorphClass();
        $users = User::whereIn('id', $rows->where('actor_type', $userType)->pluck('actor_id'))->pluck('name', 'id');
        $guests = Guest::withTrashed()->whereIn('id', $rows->where('actor_type', $guestType)->pluck('actor_id'))->pluck('name', 'id');

        $p->through(function (EventActivity $a) use ($userType, $guestType, $users, $guests) {
            $byStaff = (bool) ($a->payload['by_staff'] ?? false);
            $actor = null;
            if (! $byStaff && $a->actor_id) {
                $name = $a->actor_type === $userType ? ($users[$a->actor_id] ?? null) : ($a->actor_type === $guestType ? ($guests[$a->actor_id] ?? null) : null);
                $actor = $name ? ['type' => $a->actor_type === $guestType ? 'guest' : 'user', 'name' => $name] : null;
            }

            return [
                'id' => $a->id,
                'type' => $a->type,
                'createdAt' => $a->created_at?->toIso8601String(),
                'actor' => $actor,
                'byStaff' => $byStaff,
                'payload' => array_intersect_key($a->payload ?? [], array_flip(self::PAYLOAD_KEYS)),
            ];
        });

        return $this->shape($p);
    }

    public function staff(Events $event, int $page, int $perPage): array
    {
        $p = AdminAuditLog::where('event_id', $event->id)->with('actor:id,name')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (AdminAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'createdAt' => $r->created_at?->toIso8601String(),
                'actor' => $r->actor ? ['id' => $r->actor->id, 'name' => $r->actor->name] : null,
                'reason' => $r->reason,
                'before' => $r->before,
                'after' => $r->after,
                'impersonated' => $r->impersonated_by !== null,
            ]);

        return $this->shape($p);
    }

    private function shape(LengthAwarePaginator $p): array
    {
        return ['data' => $p->items(), 'meta' => ['page' => $p->currentPage(), 'perPage' => $p->perPage(), 'total' => $p->total(), 'lastPage' => $p->lastPage()]];
    }
}
