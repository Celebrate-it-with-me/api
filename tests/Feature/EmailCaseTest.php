<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('stores the email in lowercase at sign-up', function () {
    $this->postJson('/api/v1/app/register', ['name' => 'Ana', 'email' => '  Ana@Example.COM ', 'password' => 'Password123'])->assertCreated();

    expect(User::query()->pluck('email')->all())->toContain('ana@example.com');
});

it('refuses a sign-up that differs only by case from an existing account', function () {
    User::factory()->create(['email' => 'Ana@Example.com']);

    $this->postJson('/api/v1/app/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Password123'])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    $this->postJson('/api/v1/app/register', ['name' => 'Ana', 'email' => 'ANA@example.com', 'password' => 'Password123'])
        ->assertStatus(422);
});

it('logs in whatever the case of the address', function () {
    User::factory()->create(['email' => 'Ana@Example.com', 'password' => bcrypt('OriginalPass1')]);
    $this->get('/sanctum/csrf-cookie');

    $this->withHeaders(['Origin' => 'http://dev.app.cwm.com', 'Referer' => 'http://dev.app.cwm.com/'])
        ->postJson('/api/v1/app/login', ['email' => 'ANA@example.com', 'password' => 'OriginalPass1', 'device' => 'x'])
        ->assertOk();
});

it('has a unique index on lower(email) that the database enforces', function () {
    User::factory()->create(['email' => 'one@example.com']);

    expect(fn () => DB::table('users')->insert(['name' => 'x', 'email' => 'ONE@example.com', 'password' => 'x']))
        ->toThrow(QueryException::class);
});

it('skips the index and logs when case duplicates already exist, deleting nothing', function () {
    DB::statement('DROP INDEX users_email_lower_unique');
    User::factory()->create(['email' => 'dup@example.com']);
    DB::table('users')->insert(['name' => 'x', 'email' => 'DUP@example.com', 'password' => 'x']);
    Log::spy();

    (require base_path('database/migrations/2027_07_11_000001_add_lower_email_unique_to_users.php'))->up();

    Log::shouldHaveReceived('warning')->once();
    expect(User::query()->where(DB::raw('lower(email)'), 'dup@example.com')->count())->toBe(2)
        ->and(DB::selectOne("select count(*) c from pg_indexes where indexname = 'users_email_lower_unique'")->c)->toBe(0);

    // Once the owner resolves it, running the migration again creates the index.
    DB::table('users')->where('email', 'DUP@example.com')->delete();
    (require base_path('database/migrations/2027_07_11_000001_add_lower_email_unique_to_users.php'))->up();
    expect(DB::selectOne("select count(*) c from pg_indexes where indexname = 'users_email_lower_unique'")->c)->toBe(1);
});

it('drops the index on down', function () {
    (require base_path('database/migrations/2027_07_11_000001_add_lower_email_unique_to_users.php'))->down();

    expect(DB::selectOne("select count(*) c from pg_indexes where indexname = 'users_email_lower_unique'")->c)->toBe(0);
});
