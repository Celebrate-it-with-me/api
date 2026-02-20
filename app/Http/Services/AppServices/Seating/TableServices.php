<?php

namespace App\Http\Services\AppServices\Seating;

use App\Models\Events;
use App\Models\Guest;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use Illuminate\Support\Facades\DB;

class TableServices
{
    public function getTablesForEvent(Events $event): array
    {
        $tables = Table::forEvent($event->id)
            ->byPriority()
            ->with(['assignedGuests'])
            ->withAssignedCount()
            ->get();

        return [
            'tables' => $tables,
            'meta' => [
                'total_tables' => $tables->count(),
                'total_capacity' => $tables->sum('capacity'),
                'total_assigned' => $tables->sum('assigned_guests_count'),
            ]
        ];
    }

    public function createTable(array $data): Table
    {
        return Table::create($data);
    }

    public function updateTable(Table $table, array $data, ?string $originalName = null): Table
    {
        $table->update($data);

        if (isset($data['name']) && $data['name'] !== $originalName) {
            $this->updateSeatNumbers($table);
        }

        return $table;
    }

    public function deleteTable(Table $table): int
    {
        $assignedCount = $table->assignments()->count();
        $table->delete();

        return $assignedCount;
    }

    public function assignGuest(Table $table, int $guestId, int $assignedBy): TableAssignment
    {
        return DB::transaction(function () use ($table, $guestId, $assignedBy) {
            return TableAssignment::create([
                'table_id' => $table->id,
                'guest_id' => $guestId,
                'seat_number' => $table->getNextSeatNumber(),
                'assigned_at' => now(),
                'assigned_by' => $assignedBy,
            ]);
        });
    }

    public function removeGuest(Table $table, int $guestId): bool
    {
        $assignment = TableAssignment::where('table_id', $table->id)
            ->where('guest_id', $guestId)
            ->first();

        if (!$assignment) {
            return false;
        }

        return DB::transaction(function () use ($assignment, $table) {
            $assignment->delete();
            $this->updateSeatNumbers($table);
            return true;
        });
    }

    public function getUnassignedGuests(Events $event)
    {
        $assignedGuestIds = TableAssignment::whereHas('table', function ($query) use ($event) {
            $query->where('event_id', $event->id);
        })->pluck('guest_id');

        return Guest::where('event_id', $event->id)
            ->whereNotIn('id', $assignedGuestIds)
            ->get();
    }

    public function bulkAssign(Events $event, array $assignments, int $assignedBy): array
    {
        return DB::transaction(function () use ($event, $assignments, $assignedBy) {
            $totalAssigned = 0;
            $errors = [];

            foreach ($assignments as $assignment) {
                $table = Table::find($assignment['table_id']);

                if ($table->event_id !== $event->id) {
                    $errors[] = "Table {$table->name} does not belong to this event.";
                    continue;
                }

                foreach ($assignment['guest_ids'] as $guestId) {
                    if (!$table->hasAvailableSeats()) {
                        $errors[] = "Table {$table->name} is full.";
                        break;
                    }

                    $existingAssignment = TableAssignment::where('guest_id', $guestId)->first();
                    if ($existingAssignment) {
                        $errors[] = "Guest ID {$guestId} is already assigned.";
                        continue;
                    }

                    TableAssignment::create([
                        'table_id' => $table->id,
                        'guest_id' => $guestId,
                        'seat_number' => $table->getNextSeatNumber(),
                        'assigned_at' => now(),
                        'assigned_by' => $assignedBy,
                    ]);

                    $totalAssigned++;
                    $table->refresh();
                }
            }

            return [
                'total_assigned' => $totalAssigned,
                'errors' => $errors,
            ];
        });
    }

    public function clearAllAssignments(Events $event): int
    {
        return DB::transaction(function () use ($event) {
            return TableAssignment::whereHas('table', function ($query) use ($event) {
                $query->where('event_id', $event->id);
            })->delete();
        });
    }

    public function updateSeatNumbers(Table $table): void
    {
        $assignments = $table->assignments()->get();

        foreach ($assignments as $index => $assignment) {
            $assignment->update([
                'seat_number' => "{$table->name} - Seat " . ($index + 1)
            ]);
        }
    }
}
