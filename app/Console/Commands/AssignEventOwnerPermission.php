<?php

namespace App\Console\Commands;

use App\Models\Events;
use App\Models\EventUserRole;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class AssignEventOwnerPermission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:assign-owner {eventId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assigns the owner role to the organizer of the given event if not already assigned.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventId = $this->argument('eventId');
        $event = Events::query()->find($eventId);

        if (! $event) {
            $this->error("❌ Event with ID {$eventId} not found.");

            return 1;
        }

        $userId = $event->organizer_id;
        $ownerRole = Role::query()->where('name', 'owner')->first();

        if (! $ownerRole) {
            $this->error('❌ Owner role not found.');

            return 1;
        }

        $exists = EventUserRole::query()
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->where('role_id', $ownerRole->id)
            ->exists();

        if ($exists) {
            $this->info("✅ User with ID {$userId} already has the owner role for event {$eventId}.");

            return 0;
        }

        EventUserRole::query()->create([
            'event_id' => $event->id,
            'user_id' => $userId,
            'role_id' => $ownerRole->id,
        ]);

        $this->info("🎉 'owner' role assigned to organizer (User ID {$userId}) for event {$eventId}.");

        return 0;
    }
}
