<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function staleRows(): void
{
    $old = now()->subDays(1000);
    $fresh = now()->subDay();
    foreach ([['old', $old], ['fresh', $fresh]] as [$key, $at]) {
        DB::table('whatsapp_inbound_events')->insert(['event_key' => $key, 'kind' => 'message', 'created_at' => $at]);
        DB::table('whatsapp_pending_statuses')->insert(['wa_message_id' => $key, 'status' => 'sent', 'payload' => '{}', 'created_at' => $at]);
        DB::table('payment_events')->insert(['gateway' => 'stripe', 'event_id' => $key, 'type' => 'x', 'outcome' => 'processed', 'received_at' => $at]);
        DB::table('failed_jobs')->insert(['uuid' => "uuid-$key", 'connection' => 'redis', 'queue' => 'mail', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at]);
    }
}

it('deletes only the rows past their retention window', function () {
    staleRows();

    $this->artisan('data:prune')->assertSuccessful();

    expect(DB::table('whatsapp_inbound_events')->pluck('event_key')->all())->toBe(['fresh'])
        ->and(DB::table('whatsapp_pending_statuses')->pluck('wa_message_id')->all())->toBe(['fresh'])
        ->and(DB::table('payment_events')->pluck('event_id')->all())->toBe(['fresh'])
        ->and(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['uuid-fresh']);
});

it('reads the windows from config/retention.php', function () {
    staleRows();
    DB::table('payment_events')->where('event_id', 'fresh')->update(['received_at' => now()->subDays(10)]);
    config(['retention.payment_events_days' => 5]);

    $this->artisan('data:prune')->assertSuccessful();

    expect(DB::table('payment_events')->count())->toBe(0);
});

it('does not delete anything with --dry-run', function () {
    staleRows();

    $this->artisan('data:prune --dry-run')->expectsOutputToContain('Would delete 1 from payment_events')->assertSuccessful();

    expect(DB::table('payment_events')->count())->toBe(2)->and(DB::table('failed_jobs')->count())->toBe(2);
});

it('keeps open impersonation sessions and removes the ones that ended long ago', function () {
    $staff = User::factory()->create();
    $target = User::factory()->create();
    $row = fn (array $over) => DB::table('impersonation_sessions')->insert($over + [
        'staff_id' => $staff->id, 'user_id' => $target->id, 'reason' => 'support', 'notify' => true,
        'started_at' => now()->subYears(5), 'expires_at' => now()->subYears(5)->addMinutes(45), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row(['ended_at' => now()->subYears(5), 'ended_by' => 'staff']);     // ended long ago: pruned
    $row(['ended_at' => now()->subDays(3), 'ended_by' => 'expired']);    // ended recently: kept
    $row(['ended_at' => null]);                                          // never closed: kept (the close command owns it)

    $this->artisan('data:prune')->assertSuccessful();

    expect(DB::table('impersonation_sessions')->count())->toBe(2)->and(DB::table('impersonation_sessions')->whereNotNull('ended_at')->where('ended_at', '<', now()->subYear())->exists())->toBeFalse();
});

it('deletes dead admin tokens (absolute or idle) and keeps live and app tokens', function () {
    config(['admin.token_max_days' => 7, 'admin.token_idle_hours' => 12]);
    $user = User::factory()->create();
    $make = function (string $client, array $over) use ($user) {
        $id = $user->createToken($client)->accessToken->id;
        DB::table('personal_access_tokens')->where('id', $id)->update(['client' => $client] + $over);

        return $id;
    };
    $absolute = $make('admin', ['created_at' => now()->subDays(8), 'last_used_at' => now()]);
    $idle = $make('admin', ['created_at' => now()->subDays(2), 'last_used_at' => now()->subHours(13)]);
    $neverUsed = $make('admin', ['created_at' => now()->subHours(13), 'last_used_at' => null]);
    $live = $make('admin', ['created_at' => now()->subDays(2), 'last_used_at' => now()->subHours(1)]);
    $app = $make('app', ['created_at' => now()->subDays(300), 'last_used_at' => now()->subDays(200)]);

    $this->artisan('data:prune')->assertSuccessful();

    $left = DB::table('personal_access_tokens')->pluck('id')->all();
    expect($left)->toContain($live, $app)->not->toContain($absolute, $idle, $neverUsed);
});

it('is scheduled daily together with the reset token cleanup', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

    expect($commands)->toContain('data:prune')->toContain('auth:clear-resets');
});
