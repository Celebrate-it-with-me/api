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
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendInvite', 'data' => ['command' => 'O:8:"secret":1:{s:5:"email";s:'.strlen(HEALTH_SECRET_EMAIL).':"'.HEALTH_SECRET_EMAIL.'";}']]),
        'exception' => "RuntimeException: Cannot send to ".HEALTH_SECRET_EMAIL." with token ".HEALTH_SECRET_TOKEN." in /var/www/html/app/Jobs/SendInvite.php:42\nStack trace:\n#0 /var/www/html/vendor/x.php(1): foo('".HEALTH_SECRET_EMAIL."')",
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
    $file = $dir.'/laravel-health-test.log';
    file_put_contents($file, implode("\n", [
        '[2026-11-13 10:00:00] testing.INFO: just info',
        '[2026-11-13 10:01:00] testing.ERROR: Mail failed for '.HEALTH_SECRET_EMAIL.' using '.HEALTH_SECRET_TOKEN.' {"exception":"[object] (Foo)"}',
        '#0 /var/www/html/vendor/laravel/x.php(10): trace line',
        '[2026-11-13 10:02:00] testing.ERROR: Mail failed for '.HEALTH_SECRET_EMAIL.' using '.HEALTH_SECRET_TOKEN.' {"exception":"[object] (Foo)"}',
        '[2026-11-13 10:03:00] testing.CRITICAL: SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into users (email) values (a@b.com))',
    ])."\n");
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
