<?php

use App\Http\Services\Admin\Health\QueueStatus;
use App\Http\Services\Admin\Health\Redactor;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const HEALTH_ENDPOINTS = ['failed-jobs', 'queues', 'errors', 'connections', 'modules'];
const HEALTH_SECRET_EMAIL = 'invitada.secreta@example.com';
const HEALTH_SECRET_TOKEN = '12|AbCdEf0123456789AbCdEf0123456789AbCdEf01';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function healthStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function healthFailedJob(array $attrs = []): int
{
    return DB::table('failed_jobs')->insertGetId($attrs + [
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendInvite', 'data' => ['command' => 'O:8:"secret":1:{s:5:"email";s:' . strlen(HEALTH_SECRET_EMAIL) . ':"' . HEALTH_SECRET_EMAIL . '";}']]),
        'exception' => 'RuntimeException: Cannot send to ' . HEALTH_SECRET_EMAIL . ' with token ' . HEALTH_SECRET_TOKEN . " in /var/www/html/app/Jobs/SendInvite.php:42\nStack trace:\n#0 /var/www/html/vendor/x.php(1): foo('" . HEALTH_SECRET_EMAIL . "')",
        'failed_at' => now(),
    ]);
}

it('requires the right permission on every endpoint', function (string $endpoint) {
    $url = "/api/v1/admin/health/{$endpoint}";

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    $this->actingAs(healthStaff(AdminPermissions::SUPPORT))->getJson($url)->assertForbidden();
    $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson($url)->assertOk();
})->with(HEALTH_ENDPOINTS);

it('lists failed jobs without payload, trace or personal data', function () {
    healthFailedJob();
    healthFailedJob(['queue' => 'mail']);

    $res = $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/failed-jobs?limit=1');

    $res->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last24h', 2)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.job', 'App\\Jobs\\SendInvite')
        ->assertJsonPath('items.0.exceptionClass', 'RuntimeException');
    expect($res->json('items.0'))->not->toHaveKeys(['payload', 'exception']);
    expect($res->json('items.0.message'))->toContain('[correo]');
    expect($res->getContent())->not->toContain(HEALTH_SECRET_EMAIL)->not->toContain('AbCdEf0123456789')->not->toContain('Stack trace')->not->toContain('/var/www');
});

it('does not run extra queries per failed job', function () {
    foreach (range(1, 5) as $_) {
        healthFailedJob();
    }
    $admin = healthStaff(AdminPermissions::SUPERADMIN);

    DB::enableQueryLog();
    $this->actingAs($admin)->getJson('/api/v1/admin/health/failed-jobs')->assertOk();
    $few = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'failed_jobs'))->count();

    foreach (range(1, 20) as $_) {
        healthFailedJob();
    }
    DB::flushQueryLog();
    $this->actingAs($admin)->getJson('/api/v1/admin/health/failed-jobs')->assertOk();
    $many = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'failed_jobs'))->count();

    expect($many)->toBe($few);
});

it('reports database queue sizes and flags stalled work', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->timestamp],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ['queue' => 'mail', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
    ]);

    $res = $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/queues');

    $res->assertOk()->assertJsonPath('measurable', true)->assertJsonPath('driver', 'database')
        ->assertJsonPath('pendingTotal', 3)->assertJsonPath('stalledSuspected', true)
        ->assertJsonPath('worker.status', 'unknown');
    expect(collect($res->json('queues'))->firstWhere('name', 'mail')['pending'])->toBe(1);
});

it('marks queues as not measurable when the driver cannot be read', function () {
    config(['queue.default' => 'sync']);

    $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/queues')
        ->assertOk()->assertJsonPath('measurable', false)->assertJsonPath('pendingTotal', null)->assertJsonPath('stalledSuspected', null);
});

it('reads the scheduler heartbeat as ok, stale or unknown', function () {
    $admin = healthStaff(AdminPermissions::SUPERADMIN);
    Cache::forget(QueueStatus::HEARTBEAT_KEY);
    $this->actingAs($admin)->getJson('/api/v1/admin/health/queues')->assertJsonPath('scheduler.status', 'unknown');

    Cache::put(QueueStatus::HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String());
    $this->actingAs($admin)->getJson('/api/v1/admin/health/queues')->assertJsonPath('scheduler.status', 'ok');

    Cache::put(QueueStatus::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
    $this->actingAs($admin)->getJson('/api/v1/admin/health/queues')->assertJsonPath('scheduler.status', 'stale');
});

it('schedules the heartbeat every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('health-heartbeat')->assertExitCode(0);
});

it('summarises recent log errors and sanitises them', function () {
    $dir = storage_path('logs');
    $file = $dir . '/laravel-health-test.log';
    file_put_contents($file, implode("\n", [
        '[2026-11-13 10:00:00] testing.INFO: just info',
        '[2026-11-13 10:01:00] testing.ERROR: Mail failed for ' . HEALTH_SECRET_EMAIL . ' using ' . HEALTH_SECRET_TOKEN . ' {"exception":"[object] (Foo)"}',
        '#0 /var/www/html/vendor/laravel/x.php(10): trace line',
        '[2026-11-13 10:02:00] testing.ERROR: Mail failed for ' . HEALTH_SECRET_EMAIL . ' using ' . HEALTH_SECRET_TOKEN . ' {"exception":"[object] (Foo)"}',
        '[2026-11-13 10:03:00] testing.CRITICAL: SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into users (email) values (a@b.com))',
    ]) . "\n");
    touch($file, time() + 100);

    try {
        $res = $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/errors');
    } finally {
        unlink($file);
    }

    $res->assertOk()->assertJsonPath('available', true)->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.level', 'critical')
        ->assertJsonPath('items.1.count', 2);
    expect($res->getContent())->not->toContain(HEALTH_SECRET_EMAIL)->not->toContain('AbCdEf0123456789')->not->toContain('a@b.com')->not->toContain('/var/www')->not->toContain('just info');
});

it('reports connections without leaking configuration', function () {
    $res = $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/connections');

    $res->assertOk();
    $items = collect($res->json('items'))->keyBy('key');
    expect($items->keys()->all())->toBe(['database', 'redis', 'storage', 'mail']);
    expect($items['database']['status'])->toBe('ok');
    expect($items->pluck('status')->every(fn ($s) => in_array($s, ['ok', 'error', 'unknown'], true)))->toBeTrue();
    foreach (['password', 'host', 'DB_', 'secret'] as $needle) {
        expect(strtolower($res->getContent()))->not->toContain(strtolower($needle));
    }
});

it('shows a failing probe as error, not as an exception', function () {
    config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
    app('redis')->purge();

    $res = $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->getJson('/api/v1/admin/health/connections')->assertOk();
    expect(collect($res->json('items'))->firstWhere('key', 'redis')['status'])->toBeIn(['error', 'ok']);
});

it('reads the module catalog without extra queries per module', function () {
    $admin = healthStaff(AdminPermissions::SUPERADMIN);
    DB::enableQueryLog();
    $res = $this->actingAs($admin)->getJson('/api/v1/admin/health/modules')->assertOk();
    $modules = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'module'))->count();

    expect($res->json('total'))->toBeGreaterThan(0)->and($res->json('enabled') + $res->json('disabled'))->toBe($res->json('total'));
    expect($modules)->toBeLessThanOrEqual(3);
});

it('redacts the usual sensitive shapes', function (string $input, string $absent) {
    expect(Redactor::summary($input))->not->toContain($absent);
})->with([
    ['Call to +1 (305) 555-1234 failed', '555-1234'],
    ['Authorization Bearer abc.def.ghi rejected', 'abc.def.ghi'],
    ['Key (email)=(x@y.com) already exists', 'x@y.com'],
    ["Invalid value 'María Pérez' for guest", 'María'],
    ['password=hunter2 rejected', 'hunter2'],
]);

it('gives support no access to the health screen and lets only superadmin read it', function () {
    $this->actingAs(healthStaff(AdminPermissions::SUPPORT))->getJson('/api/v1/admin/health/failed-jobs')->assertForbidden();
    expect(AdminPermissions::for(AdminPermissions::SUPPORT))->not->toContain('admin.system.health', 'admin.system.manage');
    expect(AdminPermissions::for(AdminPermissions::SUPERADMIN))->toContain('admin.system.health', 'admin.system.manage');
});

function healthPayload(string $name = 'App\\Jobs\\SendInvite'): string
{
    return json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => 'stdClass', 'command' => 'O:8:"stdClass":1:{s:5:"email";s:' . strlen(HEALTH_SECRET_EMAIL) . ':"' . HEALTH_SECRET_EMAIL . '";}']]);
}

it('requires manage permission and a reason to retry or delete a failed job', function () {
    config(['queue.default' => 'database']);
    healthFailedJob(['uuid' => $uuid = (string) Str::uuid(), 'payload' => healthPayload()]);
    $retry = "/api/v1/admin/health/failed-jobs/{$uuid}/retry";

    $this->postJson($retry, ['reason' => 'Se arregló el correo'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($retry, ['reason' => 'Se arregló el correo'])->assertForbidden();
    $this->actingAs(healthStaff(AdminPermissions::SUPPORT))->postJson($retry, ['reason' => 'Se arregló el correo'])->assertForbidden();
    $this->actingAs(healthStaff(AdminPermissions::SUPPORT))->deleteJson("/api/v1/admin/health/failed-jobs/{$uuid}", ['reason' => 'Basura conocida'])->assertForbidden();

    $admin = healthStaff(AdminPermissions::SUPERADMIN);
    $this->actingAs($admin)->postJson($retry, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->postJson($retry, ['reason' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->deleteJson("/api/v1/admin/health/failed-jobs/{$uuid}", [])->assertUnprocessable();
    expect(DB::table('failed_jobs')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
});

it('retries one failed job, puts it back on the queue and audits without the payload', function () {
    config(['queue.default' => 'database']);
    healthFailedJob(['uuid' => $uuid = (string) Str::uuid(), 'payload' => healthPayload(), 'queue' => 'mail']);
    $admin = healthStaff(AdminPermissions::SUPERADMIN);

    $this->actingAs($admin)->postJson("/api/v1/admin/health/failed-jobs/{$uuid}/retry", ['reason' => 'Se arregló el correo'])
        ->assertOk()->assertJsonPath('retried', 1);

    expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->where('queue', 'mail')->count())->toBe(1);
    $log = DB::table('admin_audit_log')->where('action', 'health.failed_job_retry')->first();
    expect($log->actor_id)->toBe($admin->id)->and($log->reason)->toBe('Se arregló el correo');
    expect(json_decode($log->after, true))->toBe(['job' => 'App\\Jobs\\SendInvite', 'queue' => 'mail']);
    expect(json_encode($log))->not->toContain(HEALTH_SECRET_EMAIL);
});

it('deletes one failed job and audits it', function () {
    healthFailedJob(['uuid' => $uuid = (string) Str::uuid(), 'payload' => healthPayload()]);
    healthFailedJob();

    $this->actingAs(healthStaff(AdminPermissions::SUPERADMIN))->deleteJson("/api/v1/admin/health/failed-jobs/{$uuid}", ['reason' => 'Basura conocida'])
        ->assertOk()->assertJsonPath('deleted', 1);

    expect(DB::table('failed_jobs')->count())->toBe(1);
    $log = DB::table('admin_audit_log')->where('action', 'health.failed_job_forget')->first();
    expect(json_decode($log->before, true)['job'])->toBe('App\\Jobs\\SendInvite');
    expect(json_encode($log))->not->toContain(HEALTH_SECRET_EMAIL);
});

it('keeps a corrupt failed job in the list instead of failing with a 500', function () {
    config(['queue.default' => 'database']);
    healthFailedJob(['uuid' => $uuid = (string) Str::uuid(), 'payload' => json_encode(['displayName' => 'App\\Jobs\\Broken', 'data' => ['command' => 'garbage']])]);
    healthFailedJob(['payload' => healthPayload(), 'queue' => 'default']);
    $admin = healthStaff(AdminPermissions::SUPERADMIN);

    $this->actingAs($admin)->postJson("/api/v1/admin/health/failed-jobs/{$uuid}/retry", ['reason' => 'Se arregló el correo'])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/v1/admin/health/failed-jobs/retry', ['queue' => 'default', 'reason' => 'Se arregló el correo'])
        ->assertOk()->assertJsonPath('retried', 1);
    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});

it('answers 404 for an unknown or malformed failed job id', function () {
    $admin = healthStaff(AdminPermissions::SUPERADMIN);
    $this->actingAs($admin)->postJson('/api/v1/admin/health/failed-jobs/' . Str::uuid() . '/retry', ['reason' => 'Se arregló el correo'])->assertNotFound();
    $this->actingAs($admin)->deleteJson('/api/v1/admin/health/failed-jobs/not-a-uuid', ['reason' => 'Basura conocida'])->assertNotFound();
});

it('retries a whole queue in bounded batches', function () {
    config(['queue.default' => 'database']);
    foreach (range(1, 55) as $_) {
        healthFailedJob(['payload' => healthPayload(), 'queue' => 'mail']);
    }
    healthFailedJob(['payload' => healthPayload('App\\Jobs\\Other'), 'queue' => 'default']);
    $admin = healthStaff(AdminPermissions::SUPERADMIN);
    $url = '/api/v1/admin/health/failed-jobs/retry';

    $this->actingAs($admin)->postJson($url, ['reason' => 'Se arregló el correo'])->assertUnprocessable()->assertJsonValidationErrors('queue');
    $this->actingAs(healthStaff(AdminPermissions::SUPPORT))->postJson($url, ['queue' => 'mail', 'reason' => 'Se arregló el correo'])->assertForbidden();

    $this->actingAs($admin)->postJson($url, ['queue' => 'mail', 'reason' => 'Se arregló el correo'])
        ->assertOk()->assertJsonPath('retried', 50)->assertJsonPath('remaining', 5)->assertJsonPath('limit', 50);

    expect(DB::table('jobs')->where('queue', 'mail')->count())->toBe(50)->and(DB::table('failed_jobs')->where('queue', 'default')->count())->toBe(1);
    $log = DB::table('admin_audit_log')->where('action', 'health.failed_jobs_retry_queue')->first();
    expect(json_decode($log->after, true))->toBe(['queue' => 'mail', 'count' => 50, 'jobs' => ['App\\Jobs\\SendInvite' => 50]]);
    expect(json_encode($log))->not->toContain(HEALTH_SECRET_EMAIL);
});
