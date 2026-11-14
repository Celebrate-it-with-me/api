<?php

use App\Models\AdminAuditLog;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AdminPermissions;
use App\Support\ImpersonationRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Mail::fake();
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

const ANN_ADMIN = '/api/v1/admin/announcements';
const ANN_APP = '/api/v1/app/announcements';

function annStaff(string $role = AdminPermissions::SUPERADMIN): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function annPayload(array $over = []): array
{
    return array_merge([
        'level' => 'news', 'title_es' => 'Novedad', 'title_en' => 'News', 'body_es' => 'Texto', 'body_en' => 'Text',
        'audience' => 'all', 'starts_at' => now()->subHour()->toIso8601String(), 'reason' => 'Lanzamiento de la función',
    ], $over);
}

function annMake(array $over = []): Announcement
{
    return Announcement::create(array_merge([
        'level' => 'info', 'title_es' => 'Hola', 'title_en' => 'Hello', 'body_es' => 'Cuerpo', 'body_en' => 'Body',
        'audience' => 'all', 'starts_at' => now()->subHour(), 'dismissible' => true, 'is_active' => true,
    ], $over));
}

function annPay(User $buyer, string $status = 'paid'): EventPurchase
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);

    return EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => $status,
    ]);
}

function annEventFor(User $owner, $startDate): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
        'start_date' => $startDate, 'end_date' => $startDate,
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

describe('admin authorization', function () {
    it('closes every route to non-staff and to support, and opens it to the superadmin', function () {
        $organizer = User::factory()->create();
        $a = annMake();

        foreach ([['getJson', ANN_ADMIN], ['postJson', ANN_ADMIN . '/audience-preview'], ['postJson', ANN_ADMIN], ['putJson', ANN_ADMIN . "/{$a->id}"], ['deleteJson', ANN_ADMIN . "/{$a->id}"]] as [$m, $url]) {
            $this->actingAs($organizer)->{$m}($url, [])->assertForbidden();
        }
        $this->actingAsReauthed(annStaff(AdminPermissions::SUPPORT))->postJson(ANN_ADMIN, annPayload())->assertForbidden();
        $this->actingAsReauthed(annStaff(AdminPermissions::SUPPORT))->getJson(ANN_ADMIN)->assertForbidden();
        $this->actingAsReauthed(annStaff())->getJson(ANN_ADMIN)->assertOk();
    });

    it('needs a recent re-authentication to write, but not to read or preview', function () {
        $staff = annStaff();
        $this->actingAs($staff)->postJson(ANN_ADMIN, annPayload())->assertStatus(403);
        expect(Announcement::count())->toBe(0);
        $this->actingAs($staff)->getJson(ANN_ADMIN)->assertOk();
        $this->actingAs($staff)->postJson(ANN_ADMIN . '/audience-preview', ['audience' => 'all'])->assertOk();
    });
});

describe('admin CRUD', function () {
    it('creates, shows camelCase, updates, deletes, and audits each write with its reason', function () {
        $staff = annStaff();
        $id = $this->actingAsReauthed($staff)->postJson(ANN_ADMIN, annPayload(['cta_url' => '/billing', 'cta_label_es' => 'Ver', 'cta_label_en' => 'View']))
            ->assertCreated()
            ->assertJsonPath('data.titleEs', 'Novedad')->assertJsonPath('data.status', 'active')->assertJsonPath('data.dismissible', true)
            ->assertJsonPath('data.isActive', true)->assertJsonPath('data.endsAt', null)->assertJsonPath('data.dismissedCount', 0)
            ->assertJsonPath('data.ctaUrl', '/billing')->json('data.id');

        $this->actingAsReauthed($staff)->putJson(ANN_ADMIN . "/{$id}", annPayload(['title_es' => 'Cambiado', 'reason' => 'Corregir el título']))
            ->assertOk()->assertJsonPath('data.titleEs', 'Cambiado');
        $this->actingAsReauthed($staff)->deleteJson(ANN_ADMIN . "/{$id}", ['reason' => 'Ya no aplica'])
            ->assertOk()->assertJsonPath('data', null);

        expect(Announcement::count())->toBe(0);
        $logs = AdminAuditLog::where('subject_type', 'announcement')->orderBy('id')->get();
        expect($logs->pluck('action')->all())->toBe(['announcement.create', 'announcement.update', 'announcement.delete'])
            ->and($logs->pluck('reason')->all())->toBe(['Lanzamiento de la función', 'Corregir el título', 'Ya no aplica'])
            ->and($logs->every(fn ($l) => $l->actor_id === $staff->id && $l->subject_id === $id))->toBeTrue();
    });

    it('answers 404 announcement_not_found on update and delete of a missing one', function () {
        $staff = annStaff();
        $this->actingAsReauthed($staff)->putJson(ANN_ADMIN . '/9999', annPayload())->assertNotFound()->assertJsonPath('code', 'announcement_not_found');
        $this->actingAsReauthed($staff)->deleteJson(ANN_ADMIN . '/9999', ['reason' => 'No existe ya'])->assertNotFound()->assertJsonPath('code', 'announcement_not_found');
    });

    it('requires a reason of 5 characters for every write', function () {
        $staff = annStaff();
        $this->actingAsReauthed($staff)->postJson(ANN_ADMIN, annPayload(['reason' => 'no']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $a = annMake();
        $this->actingAsReauthed($staff)->deleteJson(ANN_ADMIN . "/{$a->id}", [])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'required');
        expect(Announcement::count())->toBe(1);
    });

    it('lists newest first with meta and filters by status', function () {
        annMake(['title_es' => 'activo']);
        annMake(['title_es' => 'futuro', 'starts_at' => now()->addDay()]);
        annMake(['title_es' => 'viejo', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        annMake(['title_es' => 'apagado', 'is_active' => false]);
        $staff = annStaff();

        $all = $this->actingAs($staff)->getJson(ANN_ADMIN)->assertOk()->assertJsonPath('meta', ['page' => 1, 'perPage' => 25, 'total' => 4]);
        expect($all->json('data.0.titleEs'))->toBe('futuro');

        foreach (['scheduled' => 'futuro', 'active' => 'activo', 'ended' => 'viejo', 'inactive' => 'apagado'] as $status => $title) {
            $res = $this->actingAs($staff)->getJson(ANN_ADMIN . "?status={$status}")->assertOk()->assertJsonCount(1, 'data');
            expect($res->json('data.0.titleEs'))->toBe($title)->and($res->json('data.0.status'))->toBe($status);
        }
        $this->actingAs($staff)->getJson(ANN_ADMIN . '?status=zzz')->assertUnprocessable();
        $this->actingAs($staff)->getJson(ANN_ADMIN . '?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.perPage', 2);
    });

    it('counts the users who dismissed it', function () {
        $a = annMake();
        AnnouncementDismissal::create(['announcement_id' => $a->id, 'user_id' => User::factory()->create()->id, 'dismissed_at' => now()]);
        $this->actingAs(annStaff())->getJson(ANN_ADMIN)->assertJsonPath('data.0.dismissedCount', 1);
    });
});

describe('admin validation', function () {
    function annAssert($test, array $over, string $field, string $code): void
    {
        $test->actingAsReauthed(annStaff())->postJson(ANN_ADMIN, annPayload($over))
            ->assertUnprocessable()->assertJsonPath("errors.{$field}.0", $code);
    }

    it('answers the contract codes', function () {
        annAssert($this, ['title_es' => null], 'title_es', 'required');
        annAssert($this, ['body_en' => null], 'body_en', 'required');
        annAssert($this, ['title_en' => str_repeat('a', 81)], 'title_en', 'too_long');
        annAssert($this, ['body_es' => str_repeat('a', 401)], 'body_es', 'too_long');
        annAssert($this, ['cta_url' => '/x', 'cta_label_es' => str_repeat('a', 31), 'cta_label_en' => 'x'], 'cta_label_es', 'too_long');
        annAssert($this, ['level' => 'urgent'], 'level', 'invalid_level');
        annAssert($this, ['audience' => 'vip'], 'audience', 'invalid_audience');
        annAssert($this, ['starts_at' => null], 'starts_at', 'required');
        annAssert($this, ['ends_at' => now()->subDay()->toIso8601String()], 'ends_at', 'ends_before_start');
        annAssert($this, ['cta_url' => '/x', 'cta_label_es' => 'Ver'], 'cta_label_en', 'cta_label_required');
        annAssert($this, ['cta_url' => '/x', 'cta_label_en' => 'View'], 'cta_label_es', 'cta_label_required');
        annAssert($this, ['cta_url' => '/x'], 'cta_label_es', 'cta_label_required');
        annAssert($this, ['cta_url' => '/x'], 'cta_label_en', 'cta_label_required');
        annAssert($this, ['audience' => 'upcoming_event'], 'upcoming_days', 'upcoming_days_required');
        annAssert($this, ['audience' => 'upcoming_event', 'upcoming_days' => 400], 'upcoming_days', 'invalid_days');
        annAssert($this, ['audience' => 'upcoming_event', 'upcoming_days' => 0], 'upcoming_days', 'invalid_days');
        annAssert($this, ['audience' => 'upcoming_event', 'upcoming_days' => 'abc'], 'upcoming_days', 'invalid_days');
        annAssert($this, ['audience' => 'all', 'upcoming_days' => 1.5], 'upcoming_days', 'invalid_days');
    });

    it('rejects malicious or unsupported urls', function () {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,hi', 'http://example.com', '//evil.com', '/\\evil.com', 'https://', 'ftp://x.com', 'example.com', "/ok\n/x", 'https://ex ample.com'] as $url) {
            annAssert($this, ['cta_url' => $url, 'cta_label_es' => 'Ver', 'cta_label_en' => 'View'], 'cta_url', 'invalid_url');
        }
        expect(Announcement::count())->toBe(0);
    });

    it('accepts https and internal urls', function () {
        foreach (['https://quinvite.com/novedades?x=1', '/dashboard/billing'] as $url) {
            $this->actingAsReauthed(annStaff())->postJson(ANN_ADMIN, annPayload(['cta_url' => $url, 'cta_label_es' => 'Ver', 'cta_label_en' => 'View']))->assertCreated();
        }
    });

    it('stores plain text: tags are stripped, and days are dropped unless the audience uses them', function () {
        $res = $this->actingAsReauthed(annStaff())->postJson(ANN_ADMIN, annPayload(['title_es' => '<script>alert(1)</script>Hola <b>mundo</b>', 'upcoming_days' => 30]))->assertCreated();
        expect($res->json('data.titleEs'))->not->toContain('<')->and($res->json('data.upcomingDays'))->toBeNull();
    });

    it('keeps the days for the upcoming_event audience', function () {
        $this->actingAsReauthed(annStaff())->postJson(ANN_ADMIN, annPayload(['audience' => 'upcoming_event', 'upcoming_days' => 30]))
            ->assertCreated()->assertJsonPath('data.upcomingDays', 30);
    });
});

describe('audiences', function () {
    it('previews the count of each audience', function () {
        $free = User::factory()->create();
        $paid = User::factory()->create();
        $refunded = User::factory()->create();
        $soon = User::factory()->create();
        annPay($paid);
        annPay($refunded, 'refunded');
        annPay($soon, 'pending');
        annEventFor($soon, now()->addDays(10));
        annStaff();
        $base = User::query()->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', AdminPermissions::STAFF_ROLES))->count();
        $staff = annStaff();
        $count = fn (array $body) => $this->actingAs($staff)->postJson(ANN_ADMIN . '/audience-preview', $body)->assertOk()->json('data.count');

        expect($count(['audience' => 'all']))->toBe($base)
            ->and($count(['audience' => 'paid']))->toBe(1)
            ->and($count(['audience' => 'free']))->toBe($base - 1)
            ->and($count(['audience' => 'upcoming_event', 'upcoming_days' => 30]))->toBe(1)
            ->and($count(['audience' => 'upcoming_event', 'upcoming_days' => 5]))->toBe(0);
        $this->actingAs($staff)->postJson(ANN_ADMIN . '/audience-preview', ['audience' => 'x'])->assertUnprocessable()->assertJsonPath('errors.audience.0', 'invalid_audience');
        $this->actingAs($staff)->postJson(ANN_ADMIN . '/audience-preview', ['audience' => 'upcoming_event'])->assertUnprocessable()->assertJsonPath('errors.upcoming_days.0', 'upcoming_days_required');
        $this->actingAs($staff)->postJson(ANN_ADMIN . '/audience-preview', ['audience' => 'upcoming_event', 'upcoming_days' => 999])->assertUnprocessable()->assertJsonPath('errors.upcoming_days.0', 'invalid_days');
    });

    it('shows each announcement only to its audience in the app', function () {
        $free = User::factory()->create();
        $paid = User::factory()->create();
        $soon = User::factory()->create();
        $late = User::factory()->create();
        annPay($paid);
        annEventFor($soon, now()->addDays(10));
        annEventFor($late, now()->addDays(100));
        annEventFor($free, now()->subDays(2)); // past event
        annMake(['title_es' => 'todos']);
        annMake(['title_es' => 'gratis', 'audience' => 'free']);
        annMake(['title_es' => 'pago', 'audience' => 'paid']);
        annMake(['title_es' => 'pronto', 'audience' => 'upcoming_event', 'upcoming_days' => 30]);
        $titles = fn (User $u) => collect($this->actingAs($u)->getJson(ANN_APP . '?lang=es')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        expect($titles($free))->toBe(['gratis', 'todos'])
            ->and($titles($paid))->toBe(['pago', 'todos'])
            ->and($titles($soon))->toBe(['gratis', 'pronto', 'todos'])
            ->and($titles($late))->toBe(['gratis', 'todos']);
    });
});

describe('app listing', function () {
    it('requires authentication', function () {
        $this->getJson(ANN_APP)->assertUnauthorized();
        $this->postJson(ANN_APP . '/1/dismiss')->assertUnauthorized();
    });

    it('hides inactive, future and ended ones', function () {
        annMake(['title_es' => 'visible']);
        annMake(['title_es' => 'futuro', 'starts_at' => now()->addMinute()]);
        annMake(['title_es' => 'terminado', 'ends_at' => now()->subMinute()]);
        annMake(['title_es' => 'apagado', 'is_active' => false]);
        annMake(['title_es' => 'con fin', 'ends_at' => now()->addDay()]);

        $res = $this->actingAs(User::factory()->create())->getJson(ANN_APP . '?lang=es')->assertOk();
        expect(collect($res->json('data'))->pluck('title')->sort()->values()->all())->toBe(['con fin', 'visible']);
    });

    it('localizes by lang, falls back to the account language, then to Spanish', function () {
        annMake(['title_es' => 'Hola', 'title_en' => 'Hello', 'body_es' => 'Cuerpo', 'body_en' => 'Body', 'cta_url' => '/x', 'cta_label_es' => 'Ver', 'cta_label_en' => 'View']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(ANN_APP . '?lang=en')->assertJsonPath('data.0', ['id' => Announcement::first()->id, 'level' => 'info', 'title' => 'Hello', 'body' => 'Body', 'ctaLabel' => 'View', 'ctaUrl' => '/x', 'dismissible' => true]);
        $this->actingAs($user)->getJson(ANN_APP)->assertJsonPath('data.0.title', 'Hola');
        $this->actingAs($user)->getJson(ANN_APP . '?lang=fr')->assertJsonPath('data.0.title', 'Hola');
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['language' => 'en']);
        $this->actingAs($user->fresh())->getJson(ANN_APP)->assertJsonPath('data.0.title', 'Hello');
    });

    it('returns null button fields when there is no button', function () {
        annMake();
        $this->actingAs(User::factory()->create())->getJson(ANN_APP)->assertJsonPath('data.0.ctaLabel', null)->assertJsonPath('data.0.ctaUrl', null);
    });

    it('returns at most 3, warning before news before info, then newest', function () {
        annMake(['title_es' => 'info nuevo', 'level' => 'info', 'starts_at' => now()->subMinute()]);
        annMake(['title_es' => 'news viejo', 'level' => 'news', 'starts_at' => now()->subDays(2)]);
        annMake(['title_es' => 'news nuevo', 'level' => 'news', 'starts_at' => now()->subHour()]);
        annMake(['title_es' => 'warning', 'level' => 'warning', 'starts_at' => now()->subDays(5)]);
        annMake(['title_es' => 'info viejo', 'level' => 'info', 'starts_at' => now()->subDays(9)]);

        $titles = collect($this->actingAs(User::factory()->create())->getJson(ANN_APP . '?lang=es')->json('data'))->pluck('title')->all();
        expect($titles)->toBe(['warning', 'news nuevo', 'news viejo']);
    });

    it('does not show staff-only content to the organizer, and never exposes tags', function () {
        annMake(['title_es' => 'x']);
        $res = $this->actingAs(User::factory()->create())->getJson(ANN_APP)->assertOk();
        expect(array_keys($res->json('data.0')))->toBe(['id', 'level', 'title', 'body', 'ctaLabel', 'ctaUrl', 'dismissible']);
    });
});

describe('dismiss', function () {
    it('hides it for her only, and is idempotent', function () {
        $a = annMake();
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($me)->postJson(ANN_APP . "/{$a->id}/dismiss")->assertOk()->assertJsonPath('data', null);
        $this->actingAs($me)->postJson(ANN_APP . "/{$a->id}/dismiss")->assertOk();
        $this->actingAs($me)->getJson(ANN_APP)->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson(ANN_APP)->assertJsonCount(1, 'data');
        expect(AnnouncementDismissal::count())->toBe(1);
    });

    it('answers 404 for a missing one and for one outside her audience or dates', function () {
        $me = User::factory()->create();
        $notMine = annMake(['audience' => 'paid']);
        $future = annMake(['starts_at' => now()->addDay()]);
        $off = annMake(['is_active' => false]);

        foreach ([9999, $notMine->id, $future->id, $off->id] as $id) {
            $this->actingAs($me)->postJson(ANN_APP . "/{$id}/dismiss")->assertNotFound()->assertJsonPath('code', 'announcement_not_found');
        }
        expect(AnnouncementDismissal::count())->toBe(0);
    });

    it('answers 422 not_dismissible when it cannot be closed', function () {
        $a = annMake(['level' => 'warning', 'dismissible' => false]);
        $this->actingAs(User::factory()->create())->postJson(ANN_APP . "/{$a->id}/dismiss")
            ->assertUnprocessable()->assertJsonPath('errors.announcement.0', 'not_dismissible');
        expect(AnnouncementDismissal::count())->toBe(0);
    });
});

describe('impersonation', function () {
    it('classifies the list as allowed and the dismiss as blocked', function () {
        expect(ImpersonationRules::ALLOWED)->toContain('GET api/v1/app/announcements')
            ->and(ImpersonationRules::BLOCKED)->toContain('POST api/v1/app/announcements/{announcement}/dismiss')
            ->and(ImpersonationRules::BLOCKED)->not->toContain('GET api/v1/app/announcements')
            ->and(ImpersonationRules::ALLOWED)->not->toContain('POST api/v1/app/announcements/{announcement}/dismiss');
    });
});
