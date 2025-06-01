<?php

use App\Models\Events;
use App\Models\EventUserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ownerRole = Role::firstOrCreate(['name' => 'owner']);

        Events::whereNotNull('organizer_id')->chunk(100, function ($events) use ($ownerRole) {
            foreach ($events as $event) {
                EventUserRole::updateOrCreate(
                    ['event_id' => $event->id, 'user_id' => $event->organizer_id],
                    ['role_id' => $ownerRole->id]
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ownerRole = Role::where('name', 'owner')->first();

        if ($ownerRole) {
            EventUserRole::where('role_id', $ownerRole->id)->delete();
        }
    }
};
