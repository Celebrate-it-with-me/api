<?php

use App\Mail\SupportRequestMail;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\SupportRequest;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function supportStaff(string $role = 'support'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function supportEventFor(User $member, string $role = 'owner'): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $member->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return $event;
}

function makeRequest(User $user, array $attrs = []): SupportRequest
{
    return SupportRequest::create(['user_id' => $user->id, 'message' => 'No puedo subir fotos', 'context' => ['screen' => 'fotos'], 'status' => 'new', ...$attrs]);
}

describe('app: sending a request', function () {
    it('creates the request and mails the support box', function () {
        Mail::fake();
        config(['services.support.email' => 'ayuda@example.test', 'services.support.admin_url' => 'https://admin.example.test']);
        $user = User::factory()->create();
        $event = supportEventFor($user);

        $res = $this->actingAs($user)->postJson('/api/v1/app/support', [
            'message' => 'No puedo subir fotos',
            'eventId' => $event->id,
            'context' => ['screen' => 'Recuerdos', 'route' => '/events/1/memories', 'plan' => 'basic', 'language' => 'es'],
        ])->assertCreated();

        $row = SupportRequest::findOrFail($res->json('id'));
        expect($row->user_id)->toBe($user->id)->and($row->event_id)->toBe($event->id)
            ->and($row->status)->toBe('new')->and($row->context['screen'])->toBe('Recuerdos');

        Mail::assertSent(SupportRequestMail::class, function (SupportRequestMail $m) use ($row) {
            return $m->hasTo('ayuda@example.test')
                && str_contains($m->render(), 'No puedo subir fotos')
                && str_contains($m->render(), "https://admin.example.test/support/{$row->id}");
        });
    });

    it('defaults the mailbox to soporte@quinvite.com', function () {
        expect(config('services.support.email'))->toBe('soporte@quinvite.com');
    });

    it('needs a session', function () {
        $this->postJson('/api/v1/app/support', ['message' => 'Hola, ayuda'])->assertUnauthorized();
        $this->getJson('/api/v1/app/support')->assertUnauthorized();
    });

    it('validates message length', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'hey'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => str_repeat('a', 2000)])->assertCreated();
    });

    it('only accepts an event the user belongs to', function () {
        $user = User::factory()->create();
        $foreign = supportEventFor(User::factory()->create());

        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda con mi evento', 'eventId' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('eventId');
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda con mi evento', 'eventId' => 999999])->assertUnprocessable();

        $mine = supportEventFor($user, 'viewer');
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda con mi evento', 'eventId' => $mine->id])->assertCreated();
    });

    it('validates the context: allowed keys, value and total size', function () {
        $user = User::factory()->create();
        $post = fn (array $ctx) => $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda por favor', 'context' => $ctx]);

        $post(['evil' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('context');
        $post(['screen' => ['nested' => 1]])->assertUnprocessable();
        $post(['screen' => str_repeat('a', 301)])->assertUnprocessable();
        $post(array_fill_keys(['screen', 'route', 'eventName', 'plan', 'browser', 'language', 'viewport'], str_repeat('a', 300)))->assertUnprocessable();
        $post(['screen' => 'Inicio', 'eventId' => 5])->assertCreated();
    });

    it('rate limits to 5 per minute', function () {
        $user = User::factory()->create();
        foreach (range(1, 5) as $_) {
            $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda por favor'])->assertCreated();
        }
        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda por favor'])->assertStatus(429);
    });

    it('still saves the request if the mail fails', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/app/support', ['message' => 'Ayuda por favor'])->assertCreated();
        expect(SupportRequest::count())->toBe(1);
    });

    it('lists only the requester\'s own requests', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        makeRequest($a, ['message' => 'Mía uno']);
        makeRequest($b, ['message' => 'De otra']);

        $res = $this->actingAs($a)->getJson('/api/v1/app/support')->assertOk();
        expect($res->json('data'))->toHaveCount(1)
            ->and($res->json('data.0.message'))->toBe('Mía uno')
            ->and($res->json('data.0'))->not->toHaveKey('notes');
    });
});

describe('admin: permissions', function () {
    it('opens the inbox to support and superadmin only', function () {
        $req = makeRequest(User::factory()->create());
        $urls = [
            ['getJson', '/api/v1/admin/support', []],
            ['getJson', "/api/v1/admin/support/{$req->id}", []],
            ['patchJson', "/api/v1/admin/support/{$req->id}", ['status' => 'resolved']],
            ['postJson', "/api/v1/admin/support/{$req->id}/notes", ['body' => 'nota interna']],
        ];

        foreach ($urls as [$m, $url, $body]) {
            app('auth')->forgetGuards();
            $this->$m($url, $body)->assertUnauthorized();
            $this->actingAs(User::factory()->create())->$m($url, $body)->assertForbidden();
            $this->actingAs(supportStaff('support'))->$m($url, $body)->assertSuccessful();
            $this->actingAs(supportStaff('superadmin'))->$m($url, $body)->assertSuccessful();
        }
    });
});

describe('admin: inbox', function () {
    it('lists newest first with counts per status and filters', function () {
        $staff = supportStaff();
        $other = supportStaff('superadmin');
        $ana = User::factory()->create(['name' => 'Ana Rivera', 'email' => 'ana@example.test']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.test']);
        $event = supportEventFor($ana);

        $old = makeRequest($ana, ['message' => 'Primera', 'event_id' => $event->id, 'created_at' => now()->subDays(2)]);
        $mid = makeRequest($bob, ['message' => 'Segunda foto', 'status' => 'in_progress', 'assigned_to' => $staff->id, 'created_at' => now()->subDay()]);
        $new = makeRequest($bob, ['message' => 'Tercera', 'status' => 'resolved', 'assigned_to' => $other->id, 'resolved_at' => now()]);

        $res = $this->actingAs($staff)->getJson('/api/v1/admin/support')->assertOk();
        expect(collect($res->json('data'))->pluck('id')->all())->toBe([$new->id, $mid->id, $old->id])
            ->and($res->json('meta.counts'))->toBe(['new' => 1, 'in_progress' => 1, 'resolved' => 1])
            ->and($res->json('meta.total'))->toBe(3)
            ->and($res->json('data.2.event.id'))->toBe($event->id);

        $ids = fn (string $q) => collect($this->actingAs($staff)->getJson("/api/v1/admin/support?{$q}")->assertOk()->json('data'))->pluck('id')->all();
        expect($ids('status=new'))->toBe([$old->id])
            ->and($ids('assignedTo=me'))->toBe([$mid->id])
            ->and($ids('assignedTo=none'))->toBe([$old->id])
            ->and($ids("assignedTo={$other->id}"))->toBe([$new->id])
            ->and($ids("eventId={$event->id}"))->toBe([$old->id])
            ->and($ids('search=foto'))->toBe([$mid->id])
            ->and($ids('search=ANA@example'))->toBe([$old->id])
            ->and($ids('search=rivera'))->toBe([$old->id]);

        // counts ignore the status filter
        expect($this->actingAs($staff)->getJson('/api/v1/admin/support?status=new')->json('meta.counts.resolved'))->toBe(1);
        $this->actingAs($staff)->getJson('/api/v1/admin/support?status=bogus')->assertUnprocessable();
    });

    it('does not run a query per row', function () {
        $staff = supportStaff();
        foreach (range(1, 12) as $i) {
            $u = User::factory()->create();
            makeRequest($u, ['event_id' => supportEventFor($u)->id, 'assigned_to' => $staff->id]);
        }

        $this->actingAs($staff)->getJson('/api/v1/admin/support')->assertOk(); // warm up (auth, permissions cache)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($staff)->getJson('/api/v1/admin/support')->assertOk();
        $count = count(DB::getQueryLog());

        expect($count)->toBeLessThan(15);
    });

    it('shows the detail with user, event, context and notes', function () {
        $staff = supportStaff();
        $user = User::factory()->create();
        $event = supportEventFor($user);
        $req = makeRequest($user, ['event_id' => $event->id, 'context' => ['screen' => 'Invitados', 'plan' => 'basic']]);
        $this->actingAs($staff)->postJson("/api/v1/admin/support/{$req->id}/notes", ['body' => 'Le escribí por correo'])->assertCreated();

        $res = $this->actingAs($staff)->getJson("/api/v1/admin/support/{$req->id}")->assertOk();
        expect($res->json('data.user.adminPath'))->toBe("/users/{$user->id}")
            ->and($res->json('data.event.adminPath'))->toBe("/events/{$event->id}")
            ->and($res->json('data.context.plan'))->toBe('basic')
            ->and($res->json('data.notes.0.body'))->toBe('Le escribí por correo')
            ->and($res->json('data.notes.0.author.id'))->toBe($staff->id);

        $this->actingAs($staff)->getJson('/api/v1/admin/support/999999')->assertNotFound();
    });
});

describe('admin: writes', function () {
    it('changes status and assignment, sets resolved_at, and audits', function () {
        $staff = supportStaff();
        $req = makeRequest(User::factory()->create());

        $res = $this->actingAs($staff)->patchJson("/api/v1/admin/support/{$req->id}", ['status' => 'resolved', 'assignedTo' => $staff->id])->assertOk();
        expect($res->json('data.status'))->toBe('resolved')->and($res->json('data.resolvedAt'))->not->toBeNull()
            ->and($res->json('data.assignedTo.id'))->toBe($staff->id);

        $log = AdminAuditLog::where('action', 'support.update')->latest('id')->first();
        expect($log->actor_id)->toBe($staff->id)->and($log->subject_type)->toBe('support_request')->and($log->subject_id)->toBe($req->id)
            ->and($log->user_id)->toBe($req->user_id)
            ->and($log->before)->toBe(['status' => 'new', 'assignedTo' => null])
            ->and($log->after)->toBe(['status' => 'resolved', 'assignedTo' => $staff->id]);

        $reopen = $this->actingAs($staff)->patchJson("/api/v1/admin/support/{$req->id}", ['status' => 'in_progress', 'assignedTo' => null])->assertOk();
        expect($reopen->json('data.resolvedAt'))->toBeNull()->and($reopen->json('data.assignedTo'))->toBeNull();
    });

    it('rejects a bad status or an assignee who is not staff', function () {
        $staff = supportStaff();
        $req = makeRequest(User::factory()->create());

        $this->actingAs($staff)->patchJson("/api/v1/admin/support/{$req->id}", ['status' => 'done'])->assertUnprocessable();
        $this->actingAs($staff)->patchJson("/api/v1/admin/support/{$req->id}", ['assignedTo' => User::factory()->create()->id])->assertUnprocessable();
        expect(AdminAuditLog::where('action', 'support.update')->count())->toBe(0);
    });

    it('adds internal notes and audits them', function () {
        $staff = supportStaff();
        $req = makeRequest(User::factory()->create());

        $this->actingAs($staff)->postJson("/api/v1/admin/support/{$req->id}/notes", ['body' => ''])->assertUnprocessable();
        $this->actingAs($staff)->postJson("/api/v1/admin/support/{$req->id}/notes", ['body' => 'Revisado'])->assertCreated()->assertJsonPath('data.body', 'Revisado');

        $log = AdminAuditLog::where('action', 'support.note')->first();
        expect($log->actor_id)->toBe($staff->id)->and($log->subject_id)->toBe($req->id)->and($req->notes()->count())->toBe(1);
    });
});
