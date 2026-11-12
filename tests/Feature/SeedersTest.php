<?php

use App\Http\Services\AppServices\Hydrate\HydrationService;
use App\Models\BudgetCategory;
use App\Models\EventPlan;
use App\Models\EventType;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\RolesAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

describe('reference data', function () {
    it('is idempotent', function () {
        $this->seed(DatabaseSeeder::class);
        $first = [
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'types' => EventType::count(),
            'plans' => EventPlan::count(),
            'categories' => BudgetCategory::count(),
            'users' => User::count(),
        ];

        $this->seed(DatabaseSeeder::class);

        expect([
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'types' => EventType::count(),
            'plans' => EventPlan::count(),
            'categories' => BudgetCategory::count(),
            'users' => User::count(),
        ])->toBe($first);
    });

    it('creates the global and event roles', function () {
        $this->seed(RolesAndPermissionSeeder::class);

        expect(Role::pluck('name')->all())->toContain('admin', 'appUser', 'owner', 'editor', 'viewer');
    });

    it('gives each event role the expected permissions', function () {
        $this->seed(RolesAndPermissionSeeder::class);

        $owner = Role::findByName('owner')->permissions->pluck('name');
        $editor = Role::findByName('editor')->permissions->pluck('name');
        $viewer = Role::findByName('viewer')->permissions->pluck('name');

        expect($owner->all())->toContain(...RolesAndPermissionSeeder::EVENT_PERMISSIONS)
            ->and($editor->all())->toContain('edit_event', 'create_guest')
            ->and($editor->all())->not->toContain('delete_event', 'manage_collaborators')
            ->and($viewer->all())->toContain('view_event')
            ->and($viewer->all())->not->toContain('edit_event', 'create_guest')
            // Event roles must never grant the admin-panel permission.
            ->and($owner->all())->not->toContain('manage')
            ->and($editor->all())->not->toContain('manage')
            ->and($viewer->all())->not->toContain('manage');
    });

    it('creates the admin user with the admin role', function () {
        config(['app.admin_user.email' => 'root@example.test', 'app.admin_user.password' => 'secret-pass']);

        $this->seed(RolesAndPermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);

        expect(User::where('email', 'root@example.test')->first()->hasRole('admin'))->toBeTrue();
    });
});

describe('admin user', function () {
    it('replaces the default migration password with the configured one', function () {
        $this->seed(RolesAndPermissionSeeder::class);
        // Same situation as the 2025_04_05 migration: admin with password 'password'.
        $admin = User::factory()->create(['email' => 'root@example.test', 'password' => 'password']);
        config(['app.admin_user.email' => 'root@example.test', 'app.admin_user.password' => 'a-strong-pass']);

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();
        expect(Hash::check('a-strong-pass', $admin->password))->toBeTrue()
            ->and(Hash::check('password', $admin->password))->toBeFalse()
            ->and($admin->hasRole('admin'))->toBeTrue();
    });

    it('never overwrites a password that was changed on purpose', function () {
        $this->seed(RolesAndPermissionSeeder::class);
        $admin = User::factory()->create(['email' => 'root@example.test', 'password' => 'my-own-password']);
        config(['app.admin_user.email' => 'root@example.test', 'app.admin_user.password' => 'a-strong-pass']);

        $this->seed(AdminUserSeeder::class);

        expect(Hash::check('my-own-password', $admin->refresh()->password))->toBeTrue();
    });
});

describe('demo data', function () {
    beforeEach(function () {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
    });

    it('creates three events with different completeness', function () {
        expect(Events::count())->toBe(3);

        $xv = Events::where('custom_url_slug', 'xv-de-valentina')->first();
        $wedding = Events::where('custom_url_slug', 'boda-camila-y-andres')->first();
        $shower = Events::where('custom_url_slug', 'xv-de-sofia')->first();

        expect($xv->eventType->slug)->toBe('quinceanera')
            ->and($xv->eventPlan->slug)->toBe('premium')
            ->and($xv->guests()->count())->toBeGreaterThan(30)
            ->and($xv->menus()->count())->toBe(3)
            ->and(Guest::where('event_id', $xv->id)->whereNotNull('parent_id')->count())->toBeGreaterThan(0)
            ->and($wedding->guests()->count())->toBeGreaterThan(0)
            ->and($shower->guests()->count())->toBe(0);
    });

    it('is idempotent', function () {
        $this->seed(DemoSeeder::class);

        expect(Events::count())->toBe(3)
            ->and(User::whereIn('email', ['marisol@cwm.test', 'carlos@cwm.test', 'lucia@cwm.test', 'daniel@cwm.test'])->count())->toBe(4);
    });

    it('does not repeat guest codes inside an event', function () {
        $xv = Events::where('custom_url_slug', 'xv-de-valentina')->first();

        $codes = Guest::where('event_id', $xv->id)->pluck('code');

        expect($codes->unique()->count())->toBe($codes->count());
    });

    it('gives every member of the event the permissions of their role', function () {
        $xv = Events::where('custom_url_slug', 'xv-de-valentina')->first();

        $owner = User::where('email', 'marisol@cwm.test')->first();
        $editor = User::where('email', 'carlos@cwm.test')->first();
        $viewer = User::where('email', 'lucia@cwm.test')->first();

        expect($owner->getEventPermissions($xv))->toContain('view_event', 'edit_event', 'delete_event')
            ->and($editor->getEventPermissions($xv))->toContain('edit_event')->not->toContain('delete_event')
            ->and($viewer->getEventPermissions($xv))->toContain('view_event')->not->toContain('edit_event');
    });

    it('hydrates the active event with its permissions and features', function () {
        $owner = User::where('email', 'marisol@cwm.test')->first();

        $response = app(HydrationService::class)->hydrate($owner);
        $data = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($data['userPermissions'])->toContain('view_event')
            ->and($data['eventFeatures'])->not->toBeNull()
            ->and($data['menus'])->not->toBeEmpty();
    });

    it('does not let a user hydrate an event they do not belong to', function () {
        $wedding = Events::where('custom_url_slug', 'boda-camila-y-andres')->first();
        $viewer = User::where('email', 'lucia@cwm.test')->first();

        $response = app(HydrationService::class)->hydrateForEvent($viewer, $wedding->id);

        expect($response->getStatusCode())->toBe(403);
    });
});

describe('event types', function () {
    it('only offers quinceanera and wedding', function () {
        $this->seed(DatabaseSeeder::class);

        expect(EventType::active()->pluck('slug')->sort()->values()->all())->toBe(['quinceanera', 'wedding'])
            ->and(EventType::count())->toBeGreaterThan(2);
    });

    it('keeps hidden types out of the hydration payload', function () {
        $this->seed(DatabaseSeeder::class);

        $slugs = app(\App\Http\Services\AppServices\Hydrate\EventCacheService::class)
            ->getEventTypes()->pluck('slug')->sort()->values()->all();

        expect($slugs)->toBe(['quinceanera', 'wedding']);
    });

    it('rejects creating an event with a hidden type', function () {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $hidden = EventType::where('slug', 'birthday')->first();
        $active = EventType::where('slug', 'quinceanera')->first();
        $payload = [
            'eventName' => 'Test', 'startDate' => '12/01/2027 18:00', 'endDate' => '12/01/2027 23:00',
            'status' => 'draft', 'visibility' => 'private',
        ];

        $this->actingAs($user)->postJson('/api/v1/app/event', $payload + ['eventType' => $hidden->id])
            ->assertStatus(422)->assertJsonValidationErrors('eventType');
        $this->actingAs($user)->postJson('/api/v1/app/event', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('eventType');
    });
});

describe('profile update', function () {
    it('saves name and an E.164 phone, and clears the phone when empty', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/app/user/update-profile', ['name' => 'Marisol F.', 'phone' => '+13055550123'])
            ->assertOk();
        expect($user->fresh())->name->toBe('Marisol F.')->phone->toBe('+13055550123');

        $this->actingAs($user)->postJson('/api/v1/app/user/update-profile', ['name' => 'Marisol F.', 'phone' => null])
            ->assertOk();
        expect($user->fresh()->phone)->toBeNull();
    });

    it('rejects a missing name and a phone that is not E.164', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/app/user/update-profile', ['name' => '', 'phone' => '305 555 0123'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
    });
});

describe('password change', function () {
    it('changes the password and reports a wrong current one as a field error', function () {
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
        $payload = ['currentPassword' => 'old-password-1', 'newPassword' => 'new-password-1', 'newPasswordConfirmation' => 'new-password-1'];

        $this->actingAs($user)->postJson('/api/v1/app/user/update-password', ['currentPassword' => 'nope'] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors('currentPassword');

        $this->actingAs($user)->postJson('/api/v1/app/user/update-password', $payload)->assertOk();
        expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();
    });
});
