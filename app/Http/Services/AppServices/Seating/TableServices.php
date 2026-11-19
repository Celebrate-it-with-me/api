<?php

namespace App\Http\Services\AppServices\Seating;

use App\Exceptions\SeatingException;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TableServices
{
    /** RSVP values a guest can have (RsvpServices): the unassigned list filters by them. */
    public const STATUSES = ['attending', 'pending', 'not-attending'];

    public function getTablesForEvent(Events $event): array
    {
        $tables = Table::forEvent($event->id)
            ->byPriority()
            // The list shows seats and names only: the relation's own eager load of each guest's assignment is not needed.
            ->with(['assignedGuests' => fn ($guests) => $guests->withoutEagerLoads()->with('parent:id,name')])
            ->withAssignedCount()
            ->get();

        return [
            'tables' => $tables,
            'meta' => [
                'total_tables' => $tables->count(),
                'total_capacity' => $tables->sum('capacity'),
                'total_assigned' => $tables->sum('assigned_guests_count'),
            ],
        ];
    }

    public function createTable(array $data): Table
    {
        return Table::create($data);
    }

    /** Seats are stored as bare numbers, so renaming a table never touches them. */
    public function updateTable(Table $table, array $data): Table
    {
        $table->update($data);

        return $table;
    }

    public function deleteTable(Table $table): int
    {
        $assignedCount = $table->assignments()->count();
        $table->delete();

        return $assignedCount;
    }

    /**
     * Seats one guest. The table row is locked for the whole check-and-insert, so two requests cannot take the last
     * seat nor the same seat number.
     *
     * @throws SeatingException table_full | guest_already_seated | guest_not_in_event
     */
    public function assignGuest(Table $table, int $guestId, int $assignedBy): TableAssignment
    {
        return DB::transaction(function () use ($table, $guestId, $assignedBy) {
            $locked = Table::query()->lockForUpdate()->findOrFail($table->id);

            return $this->seat($locked, $this->lockedGuest($locked->event_id, $guestId), $assignedBy);
        });
    }

    /** Takes a guest from one table to another in one transaction; both tables are locked, lowest id first. */
    public function moveGuest(Events $event, int $guestId, int $toTableId, int $assignedBy): array
    {
        return DB::transaction(function () use ($event, $guestId, $toTableId, $assignedBy) {
            // Same lock order as everywhere else: tables first (lowest id first), then the guest.
            $current = TableAssignment::query()->where('guest_id', $guestId)->first();
            if (! $current) {
                $this->lockedGuest($event->id, $guestId);
                throw SeatingException::make('guest_not_seated');
            }

            $tables = Table::query()->where('event_id', $event->id)->whereIn('id', [$current->table_id, $toTableId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $guest = $this->lockedGuest($event->id, $guestId);
            $assignment = TableAssignment::query()->where('guest_id', $guest->id)->where('table_id', $current->table_id)->first();
            if (! $assignment) {
                throw SeatingException::make('guest_not_seated');
            }
            $from = $tables->get($assignment->table_id);
            $to = $tables->get($toTableId);
            if (! $to) {
                throw SeatingException::make('table_not_in_event', ['table' => $toTableId]);
            }
            if ($from->id === $to->id) {
                throw SeatingException::make('same_table');
            }

            $assignment->delete();
            $this->renumber($from);
            $this->seat($to, $guest, $assignedBy);

            return [$from, $to, $guest];
        });
    }

    public function removeGuest(Table $table, int $guestId): bool
    {
        return DB::transaction(function () use ($table, $guestId) {
            $locked = Table::query()->lockForUpdate()->findOrFail($table->id);
            $assignment = TableAssignment::where('table_id', $locked->id)->where('guest_id', $guestId)->first();

            if (! $assignment) {
                return false;
            }

            $assignment->delete();
            $this->renumber($locked);

            return true;
        });
    }

    /**
     * Guests of the event with no table. Filters: `search` (name, email, phone, or the name of the main guest),
     * `status` (attending | pending | not-attending | all). Paginated only when `per_page` or `page` is asked for.
     *
     * @return array{rows: iterable, total: int, counts: array<string, int>, paginator: ?LengthAwarePaginator}
     */
    public function getUnassignedGuests(Events $event, array $filters = []): array
    {
        $base = fn (): Builder => Guest::query()->where('event_id', $event->id)
            ->whereNotExists(fn ($seated) => $seated->selectRaw('1')->from('table_assignments')
                ->join('tables', 'tables.id', '=', 'table_assignments.table_id')
                ->whereColumn('table_assignments.guest_id', 'guests.id'));

        $byStatus = (clone $base())->selectRaw('guests.rsvp_status as status, count(*) as total')->groupBy('guests.rsvp_status')->pluck('total', 'status');
        $counts = [
            'attending' => (int) ($byStatus['attending'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'declined' => (int) ($byStatus['not-attending'] ?? 0),
        ];
        $counts['total'] = (int) $byStatus->sum();

        $query = $base()->with('parent:id,name')->orderBy('guests.name')->orderBy('guests.id');

        $status = $filters['status'] ?? 'all';
        if (in_array($status, self::STATUSES, true)) {
            $query->where('guests.rsvp_status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes(mb_strtolower($search), '\\%_') . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('lower(guests.name) like ?', [$like])
                    ->orWhereRaw('lower(guests.email) like ?', [$like])
                    ->orWhereRaw('lower(guests.phone) like ?', [$like])
                    ->orWhereIn('guests.parent_id', fn ($parents) => $parents->select('id')->from('guests')
                        ->whereRaw('lower(name) like ?', [$like]));
            });
        }

        if (isset($filters['per_page']) || isset($filters['page'])) {
            $paginator = $query->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 200), ['guests.*'], 'page', max((int) ($filters['page'] ?? 1), 1));

            return ['rows' => $paginator->items(), 'total' => $paginator->total(), 'counts' => $counts, 'paginator' => $paginator];
        }

        $rows = $query->get(['guests.*']);

        return ['rows' => $rows, 'total' => $rows->count(), 'counts' => $counts, 'paginator' => null];
    }

    /**
     * Seats many guests. A guest who cannot be seated (table full, already seated, repeated in the list) is skipped
     * and reported with a stable `code`; the rest are seated.
     *
     * @return array{total_assigned: int, errors: list<array<string, mixed>>, declined_ids: list<int>}
     */
    public function bulkAssign(Events $event, array $assignments, int $assignedBy): array
    {
        return DB::transaction(function () use ($event, $assignments, $assignedBy) {
            $totalAssigned = 0;
            $errors = [];
            $declined = [];

            // Lock every table involved in id order, so two bulk requests cannot deadlock nor overbook.
            $tables = Table::query()->where('event_id', $event->id)
                ->whereIn('id', collect($assignments)->pluck('table_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($assignments as $assignment) {
                $table = $tables->get((int) $assignment['table_id']);

                if (! $table) {
                    $errors[] = $this->error('table_not_in_event', (int) $assignment['table_id'], null, ['table' => $assignment['table_id']]);

                    continue;
                }

                foreach ($assignment['guest_ids'] as $guestId) {
                    try {
                        $seat = $this->seat($table, $this->lockedGuest($event->id, (int) $guestId), $assignedBy);
                    } catch (SeatingException $e) {
                        $errors[] = $this->error($e->errorCode, $table->id, (int) $guestId, ['table' => $table->name], $e->getMessage());
                        if ($e->errorCode === 'table_full') {
                            break;
                        }

                        continue;
                    }

                    $totalAssigned++;
                    if ($seat->guest->rsvp_status === 'not-attending') {
                        $declined[] = $seat->guest_id;
                    }
                }
            }

            return ['total_assigned' => $totalAssigned, 'errors' => $errors, 'declined_ids' => $declined];
        });
    }

    public function clearAllAssignments(Events $event): int
    {
        return DB::transaction(function () use ($event) {
            // Ghost rows (their guest already deleted) go too: nothing is left behind.
            return TableAssignment::withoutGlobalScopes()
                ->whereIn('table_id', Table::query()->where('event_id', $event->id)->select('id'))
                ->delete();
        });
    }

    /** Numbers the seats 1..n in their current order. */
    public function renumber(Table $table): void
    {
        $assignments = $table->assignments()->get()
            ->sortBy(fn (TableAssignment $a) => [preg_match('/(\d+)\s*$/', (string) $a->seat_number, $m) ? (int) $m[1] : PHP_INT_MAX, $a->id])
            ->values();

        foreach ($assignments as $index => $assignment) {
            $seat = (string) ($index + 1);
            if ($assignment->seat_number !== $seat) {
                $assignment->update(['seat_number' => $seat]);
            }
        }
    }

    /** The table must be locked by the caller. */
    private function seat(Table $table, Guest $guest, int $assignedBy): TableAssignment
    {
        if (TableAssignment::query()->where('guest_id', $guest->id)->exists()) {
            throw SeatingException::make('guest_already_seated');
        }
        if (! $table->hasAvailableSeats()) {
            throw SeatingException::make('table_full', ['table' => $table->name], ['table_id' => $table->id]);
        }

        $assignment = TableAssignment::create([
            'table_id' => $table->id,
            'guest_id' => $guest->id,
            'seat_number' => $table->getNextSeatNumber(),
            'assigned_at' => now(),
            'assigned_by' => $assignedBy,
        ]);

        return $assignment->setRelation('guest', $guest);
    }

    /** A live guest of this event, locked so the same person cannot be seated at two tables by two requests. */
    private function lockedGuest(int $eventId, int $guestId): Guest
    {
        $guest = Guest::query()->where('id', $guestId)->lockForUpdate()->first();

        if (! $guest || $guest->event_id !== $eventId) {
            throw SeatingException::make($guest ? 'guest_not_in_event' : 'guest_not_found');
        }

        return $guest;
    }

    /** @return array<string, mixed> */
    private function error(string $code, ?int $tableId, ?int $guestId, array $replace = [], ?string $message = null): array
    {
        return [
            'code' => $code,
            'table_id' => $tableId,
            'guest_id' => $guestId,
            'message' => $message ?? (string) __("seating.$code", $replace),
        ];
    }
}
