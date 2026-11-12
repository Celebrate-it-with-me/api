<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app enforces morph aliases (`user`, `guest`), but activities were saved with the class name,
 * so reading the actor of any activity threw and the dashboard's "recent activity" answered 500.
 */
return new class extends Migration
{
    private const ALIASES = [
        'App\Models\User' => 'user',
        'App\Models\Guest' => 'guest',
    ];

    public function up(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('event_activities')->where('actor_type', $class)->update(['actor_type' => $alias]);
        }
    }

    public function down(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('event_activities')->where('actor_type', $alias)->update(['actor_type' => $class]);
        }
    }
};
