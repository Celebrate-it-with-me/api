<?php

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Mail\ImpersonationEndedMail;
use App\Models\AdminAuditLog;
use App\Models\EventActivity;
use App\Models\EventType;
use App\Models\ImpersonationSession;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Mail::fake();
});

function impStaff(string $role = 'support'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

/** Bearer header for a plain token. The guard remembers the last user inside one test, so it is reset first. */
function impTok(string $plain): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $plain];
}

function impAs(User $user): array
{
    return impTok($user->createToken('test')->plainTextToken);
}

function impStart($test, User $staff, User $target, array $body = []): TestResponse
{
    return $test->postJson("/api/v1/admin/users/{$target->id}/impersonate", $body + ['reason' => 'Ayudar con las fotos'], impAs($staff));
}

describe('starting', function () {
    it('answers 401 to anonymous and 403 to a normal user', function () {
        $target = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$target->id}/impersonate", ['reason' => 'Ayudar con algo'])->assertUnauthorized();
        $this->postJson("/api/v1/admin/users/{$target->id}/impersonate", ['reason' => 'Ayudar con algo'], impAs(User::factory()->create()))->assertForbidden();
    });

    it('lets support and superadmin start, with a short token that is the right shape', function (string $role) {
        $staff = impStaff($role);
        $target = User::factory()->create();

        $response = impStart($this, $staff, $target)->assertCreated()
            ->assertJsonStructure(['token', 'expiresAt', 'sessionId', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.id', $target->id);

        $session = ImpersonationSession::findOrFail($response->json('sessionId'));
        $token = PersonalAccessToken::findToken($response->json('token'));

        expect($session->staff_id)->toBe($staff->id)
            ->and($session->user_id)->toBe($target->id)
            ->and($session->notify)->toBeTrue()
            ->and($session->ended_at)->toBeNull()
            ->and($token->tokenable_id)->toBe($target->id)
            ->and($token->abilities)->toBe(['impersonation'])
            ->and($token->getKey())->toBe($session->token_id)
            ->and((int) round(now()->diffInMinutes($token->expires_at, true)))->toBe(45);
    })->with(['support', 'superadmin']);

    it('takes the life of the token from the config', function () {
        config(['impersonation.ttl_minutes' => 30]);
        $response = impStart($this, impStaff(), User::factory()->create())->assertCreated();

        expect((int) round(now()->diffInMinutes(PersonalAccessToken::findToken($response->json('token'))->expires_at, true)))->toBe(30);
    });

    it('needs a reason of at least 5 characters', function () {
        $target = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$target->id}/impersonate", [], impAs(impStaff()))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/users/{$target->id}/impersonate", ['reason' => 'hey'], impAs(impStaff()))->assertUnprocessable()->assertJsonValidationErrors('reason');
        expect(ImpersonationSession::count())->toBe(0);
    });

    it('can be tied to a support request, which has to exist', function () {
        $target = User::factory()->create();
        $request = SupportRequest::create(['user_id' => $target->id, 'message' => 'Ayuda', 'status' => 'new']);

        impStart($this, impStaff(), $target, ['supportRequestId' => 999999])->assertUnprocessable()->assertJsonValidationErrors('supportRequestId');
        $id = impStart($this, impStaff(), $target, ['supportRequestId' => $request->id])->assertCreated()->json('sessionId');

        expect(ImpersonationSession::find($id)->support_request_id)->toBe($request->id);
    });

    it('refuses staff, yourself, suspended and anonymized accounts', function () {
        $staff = impStaff();

        $cases = [
            'impersonation_staff' => impStaff('support'),
            'impersonation_staff ' => impStaff('superadmin'),
            'impersonation_suspended' => tap(User::factory()->create(), fn ($u) => $u->forceFill(['suspended_at' => now()])->save()),
            'impersonation_anonymized' => tap(User::factory()->create(), fn ($u) => $u->forceFill(['anonymized_at' => now()])->save()),
        ];
        foreach ($cases as $code => $target) {
            impStart($this, $staff, $target)->assertUnprocessable()->assertJsonPath('code', trim($code));
        }
        impStart($this, $staff, $staff)->assertUnprocessable()->assertJsonPath('code', 'impersonation_self');
        expect(ImpersonationSession::count())->toBe(0);
    });

    it('refuses to impersonate yourself when the account is not staff-only (self check comes first)', function () {
        $service = app(ImpersonationService::class);
        $user = User::factory()->create();

        expect($service->refusal($user, $user, null)[0])->toBe('impersonation_self');
    });

    it('cannot chain: an impersonation token cannot impersonate anyone', function () {
        $session = impStart($this, impStaff(), $user = User::factory()->create())->json();
        $other = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$other->id}/impersonate", ['reason' => 'Encadenar'], impTok($session['token']))->assertForbidden();
        $service = app(ImpersonationService::class);
        expect($service->refusal($user, $other, PersonalAccessToken::findToken($session['token']))[0])->toBe('impersonation_chained');
    });

    it('only lets a superadmin skip the notification', function () {
        $target = User::factory()->create();

        impStart($this, impStaff('support'), $target, ['notify' => false])->assertForbidden()->assertJsonPath('code', 'impersonation_notify_forbidden');
        $id = impStart($this, impStaff('superadmin'), $target, ['notify' => false])->assertCreated()->json('sessionId');

        expect(ImpersonationSession::find($id)->notify)->toBeFalse();
    });

    it('audits the start with reason, request, notify and the staff member', function () {
        $staff = impStaff('superadmin');
        $target = User::factory()->create();
        $request = SupportRequest::create(['user_id' => $target->id, 'message' => 'Ayuda', 'status' => 'new']);

        $id = impStart($this, $staff, $target, ['reason' => 'Investigacion interna', 'supportRequestId' => $request->id, 'notify' => false])->json('sessionId');
        $row = AdminAuditLog::where('action', 'impersonation.start')->firstOrFail();

        expect($row->actor_id)->toBe($staff->id)
            ->and($row->user_id)->toBe($target->id)
            ->and($row->impersonated_by)->toBe($staff->id)
            ->and($row->reason)->toBe('Investigacion interna')
            ->and($row->after)->toMatchArray(['sessionId' => $id, 'supportRequestId' => $request->id, 'notify' => false]);
    });

    it('replaces an earlier live session of the same staff member and user, without mailing', function () {
        $staff = impStaff();
        $target = User::factory()->create();

        $first = impStart($this, $staff, $target)->json();
        $second = impStart($this, $staff, $target)->json();

        expect(ImpersonationSession::find($first['sessionId'])->ended_by)->toBe('staff')
            ->and(PersonalAccessToken::findToken($first['token']))->toBeNull()
            ->and(PersonalAccessToken::findToken($second['token']))->not->toBeNull();
        Mail::assertNothingSent();
    });
});

describe('using the token', function () {
    it('does not work in the admin panel', function () {
        $session = impStart($this, impStaff(), User::factory()->create())->json();
        $this->getJson('/api/v1/admin/userInfo', impTok($session['token']))->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard', impTok($session['token']))->assertForbidden();
    });

    it('works in the app as the user and tells the app it is support', function () {
        $user = User::factory()->create();
        $session = impStart($this, impStaff(), $user)->json();
        $tok = $session['token'];

        $this->getJson('/api/v1/app/auth/ready', impTok($tok))->assertOk()
            ->assertJsonPath('impersonating.sessionId', $session['sessionId'])
            ->assertJsonPath('impersonating.staffLabel', 'Soporte')
            ->assertJsonPath('impersonating.expiresAt', $session['expiresAt']);
        $this->getJson('/api/v1/app/user', impTok($tok))->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.impersonating.staffLabel', 'Soporte');
        $this->getJson("/api/v1/app/user/hydrate/{$user->id}", impTok($tok))->assertOk()->assertJsonPath('impersonating.staffLabel', 'Soporte');
    });

    it('does not show impersonating to a normal session', function () {
        $user = User::factory()->create();

        $this->getJson('/api/v1/app/auth/ready', impAs($user))->assertOk()->assertJsonPath('impersonating', null);
        $this->getJson("/api/v1/app/user/hydrate/{$user->id}", impAs($user))->assertOk()->assertJsonMissingPath('impersonating');
    });

    it('is never renewed, even when it is about to expire', function () {
        $session = impStart($this, impStaff(), User::factory()->create())->json();
        $token = PersonalAccessToken::findToken($session['token']);
        $token->forceFill(['expires_at' => now()->addMinutes(2)])->save();

        $this->getJson('/api/v1/app/auth/ready', impTok($session['token']))->assertOk();

        expect($token->fresh()->expires_at->lt(now()->addMinutes(5)))->toBeTrue();
    });

    it('stops working when it expires', function () {
        $session = impStart($this, impStaff(), User::factory()->create())->json();
        $this->travel(46)->minutes();

        $this->getJson('/api/v1/app/auth/ready', impTok($session['token']))->assertUnauthorized();
    });

    it('stops working with a 401 when the session was ended even if the token row survived', function () {
        $session = impStart($this, impStaff(), User::factory()->create())->json();
        ImpersonationSession::find($session['sessionId'])->forceFill(['ended_at' => now(), 'ended_by' => 'staff'])->save();

        $this->getJson('/api/v1/app/auth/ready', impTok($session['token']))->assertUnauthorized()->assertJsonPath('code', 'impersonation_ended');
    });

    it('stops working if the user is suspended meanwhile', function () {
        $user = User::factory()->create();
        $session = impStart($this, impStaff(), $user)->json();
        $user->forceFill(['suspended_at' => now()])->save();

        $this->getJson('/api/v1/app/auth/ready', impTok($session['token']))->assertForbidden()->assertJsonPath('code', 'account_suspended');
    });
});

describe('ending', function () {
    it('ends from the app: revokes the token, closes the session, audits and mails the user', function () {
        $staff = impStaff();
        $target = User::factory()->create();
        $session = impStart($this, $staff, $target, ['reason' => 'Ayudar con las fotos de la quince'])->json();
        $this->travel(12)->minutes();

        $this->postJson('/api/v1/app/impersonation/end', [], impTok($session['token']))->assertOk()->assertJsonPath('sessionId', $session['sessionId']);

        $row = ImpersonationSession::find($session['sessionId']);
        expect($row->ended_by)->toBe('staff')->and($row->ended_at)->not->toBeNull()
            ->and(PersonalAccessToken::findToken($session['token']))->toBeNull();
        $end = AdminAuditLog::where('action', 'impersonation.end')->firstOrFail();
        expect($end->actor_id)->toBe($staff->id)->and($end->impersonated_by)->toBe($staff->id)->and($end->after['endedBy'])->toBe('staff');
        Mail::assertSent(ImpersonationEndedMail::class, fn ($m) => $m->hasTo($target->email));
        $this->getJson('/api/v1/app/auth/ready', impTok($session['token']))->assertUnauthorized();
    });

    it('is not available to a normal session', function () {
        $this->postJson('/api/v1/app/impersonation/end', [], impAs(User::factory()->create()))->assertStatus(409)->assertJsonPath('code', 'not_impersonating');
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/app/impersonation/end')->assertUnauthorized();
    });

    it('does not mail when notify is false', function () {
        $session = impStart($this, impStaff('superadmin'), User::factory()->create(), ['notify' => false])->json();

        $this->postJson('/api/v1/app/impersonation/end', [], impTok($session['token']))->assertOk();

        Mail::assertNothingSent();
        expect(ImpersonationSession::find($session['sessionId'])->ended_at)->not->toBeNull();
        expect(AdminAuditLog::where('action', 'impersonation.end')->first()->after['notify'])->toBeFalse();
    });

    it('closes expired sessions with the command and mails once', function () {
        $staff = impStaff();
        $target = User::factory()->create();
        $expired = impStart($this, $staff, $target)->json();
        $live = impStart($this, $staff, User::factory()->create())->json();
        ImpersonationSession::find($expired['sessionId'])->forceFill(['expires_at' => now()->subMinutes(3), 'started_at' => now()->subMinutes(48)])->save();

        $this->artisan('impersonation:close-expired')->assertSuccessful();
        $this->artisan('impersonation:close-expired')->assertSuccessful();

        $row = ImpersonationSession::find($expired['sessionId']);
        expect($row->ended_by)->toBe('expired')->and($row->ended_at->equalTo($row->expires_at))->toBeTrue()
            ->and(PersonalAccessToken::findToken($expired['token']))->toBeNull()
            ->and(ImpersonationSession::find($live['sessionId'])->ended_at)->toBeNull();
        Mail::assertSent(ImpersonationEndedMail::class, 1);
        expect(AdminAuditLog::where('action', 'impersonation.end')->count())->toBe(1);
    });

    it('schedules the closing command every minute', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'impersonation:close-expired'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
    });
});

describe('the mail', function () {
    it('is in Spanish for a Spanish user, says what happened and never names the staff', function () {
        $staff = impStaff();
        $staff->forceFill(['name' => 'Nombre Secreto Del Staff'])->save();
        $target = User::factory()->create(['name' => 'Valentina']);
        UserPreference::create(['user_id' => $target->id, 'language' => 'es']);
        $session = impStart($this, $staff, $target, ['reason' => 'Ayudar con las fotos de la quince'])->json();
        $this->travel(7)->minutes();
        $this->postJson('/api/v1/app/impersonation/end', [], impTok($session['token']))->assertOk();

        Mail::assertSent(ImpersonationEndedMail::class, function (ImpersonationEndedMail $mail) use ($target) {
            $html = $mail->render();

            return $mail->hasTo($target->email)
                && str_contains($html, 'El equipo de Quinvite entró a tu cuenta el')
                && str_contains($html, '7 minutos')
                && str_contains($html, 'Ayudar con las fotos de la quince')
                && str_contains($html, config('services.support.email'))
                && ! str_contains($html, 'Nombre Secreto');
        });
    });

    it('is in English for an English user', function () {
        $target = User::factory()->create();
        UserPreference::create(['user_id' => $target->id, 'language' => 'en']);
        $session = impStart($this, impStaff(), $target)->json();
        $this->postJson('/api/v1/app/impersonation/end', [], impTok($session['token']))->assertOk();

        Mail::assertSent(ImpersonationEndedMail::class, fn ($m) => str_contains($m->render(), 'The Quinvite team accessed your account on') && str_contains($m->render(), '1 minute '));
    });
});

describe('event activity', function () {
    it('says "Soporte" instead of the user and keeps who it really was', function () {
        $staff = impStaff();
        $owner = User::factory()->create(['name' => 'Marisol Nombre']);
        $id = $this->actingAs($owner)->postJson('/api/v1/app/event', [
            'eventName' => 'Mis XV', 'eventType' => EventType::where('slug', 'quinceanera')->value('id'), 'startDate' => '12/01/2027 18:00',
        ])->assertSuccessful()->json('data.id');
        $session = impStart($this, $staff, $owner)->json();
        $tok = $session['token'];

        $this->patchJson("/api/v1/app/event/{$id}", ['eventName' => 'Mis XV 2'], impTok($tok))->assertOk();
        $logs = $this->getJson("/api/v1/app/event/{$id}/dashboard-logs", impTok($tok))->assertOk()->json('data');

        $mine = EventActivity::where('event_id', $id)->where('type', 'event_updated')->firstOrFail();
        expect($mine->payload['impersonated_by'])->toBe($staff->id)
            ->and($mine->actor_id)->toBe($owner->id)
            ->and(collect($logs)->pluck('message')->first(fn ($m) => str_contains($m, 'updated')))->toContain('Soporte')->not->toContain('Marisol')
            ->and(EventActivity::where('event_id', $id)->where('type', 'event_created')->first()->payload)->not->toHaveKey('impersonated_by');
    });
});
