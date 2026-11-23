<?php

namespace App\Http\Services\AppServices\Invitation;

use App\Models\Guest;
use App\Models\Seating\TableAssignment;

/**
 * "Your table" of the public invitation (docs/plans/invitation-premium-modules-contract.md, section 7): the table the
 * organizer assigned to this guest and to her companions who have not declined. Read only, per guest, and nothing about the
 * other people sitting there. No assignment, no section.
 */
class InvitationTables
{
    private const NOT_ATTENDING = 'not-attending';

    /**
     * @return array{tables: list<array{personId: int, name: string, table: string, seat: ?string}>, sameTable: bool}|null
     */
    public function forGuest(Guest $guest): ?array
    {
        // The organizer's preview uses a sample guest that is not saved.
        if (! $guest->exists) {
            return null;
        }

        // A main guest who declined does not see the block.
        if ($guest->rsvp_status === self::NOT_ATTENDING) {
            return null;
        }

        $people = collect([$guest])->concat(
            $guest->companions()->where('rsvp_status', '!=', self::NOT_ATTENDING)->orderBy('id')->get(),
        );

        $assignments = TableAssignment::query()
            ->with('table:id,name')
            ->whereIn('guest_id', $people->pluck('id'))
            ->orderBy('id')
            ->get()
            ->unique('guest_id')
            ->keyBy('guest_id');

        $rows = $people
            ->filter(fn (Guest $person) => $assignments->get($person->id)?->table !== null)
            ->map(function (Guest $person) use ($assignments) {
                $assignment = $assignments->get($person->id);
                $seat = trim((string) $assignment->seat_number);

                return [
                    'personId' => $person->id,
                    'name' => $person->name,
                    'table' => $assignment->table->name,
                    'seat' => $seat === '' ? null : $seat,
                ];
            })
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'tables' => $rows->all(),
            // Everybody listed shares the table: the page may draw one line.
            'sameTable' => $rows->pluck('table')->unique()->count() === 1,
        ];
    }
}
