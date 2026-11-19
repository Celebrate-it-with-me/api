<?php

use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Models\WhatsApp\Template;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** A template message for a family. */
function waMsg($event, Guest $g, string $status = 'sent', string $key = 'invitation', array $extra = []): Message
{
    return Message::create(array_merge([
        'event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => $key, 'locale' => 'es', 'to_phone' => '+13055550100',
        'type' => 'template', 'status' => $status, 'queued_at' => now()->subDays(3),
    ], $extra));
}

describe('audience', function () {
    it('counts families by category and groups the blocked ones by reason, companions excluded', function () {
        [$event, $u, $api] = waEvent();
        $ok1 = waGuest($event, ['name' => 'Ana']);
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $ok1->id, 'phone' => '+13055550999']);
        $ok2 = waGuest($event, ['name' => 'Beto', 'locale' => 'en']);
        waGuest($event, ['name' => 'SinTel', 'phone' => '']);
        waGuest($event, ['name' => 'Mala', 'phone' => '12']);
        $stop = waGuest($event, ['name' => 'Baja', 'phone' => '+1 305 555 0777']);
        OptOut::add('+13055550777');
        $recent = waGuest($event, ['name' => 'Reciente']);
        waMsg($event, $recent, 'sent', 'invitation', ['queued_at' => now()->subHour()]);
        $old = waGuest($event, ['name' => 'Vieja']);
        waMsg($event, $old, 'delivered');
        $failed = waGuest($event, ['name' => 'Falló']);
        waMsg($event, $failed, 'failed');

        $d = $this->actingAs($u['owner'])->getJson("$api/audience?template=invitation")->assertOk()->json('data');

        expect($d['template'])->toBe('invitation')
            ->and($d['totalFamilies'])->toBe(8)
            ->and($d['blocked'])->toBe(['no_phone' => 1, 'invalid_phone' => 1, 'opted_out' => 1, 'recently_sent' => 1, 'already_responded' => 0])
            // eligible: Ana, Beto, Vieja, Falló
            ->and($d['categories']['all']['count'])->toBe(4)
            // never got an invitation (a failed one does not count): Ana, Beto, Falló
            ->and($d['categories']['unsent']['count'])->toBe(3)
            // pending principal, got something before, a reminder would go: Vieja only (Reciente got the invitation 1 h ago, reminders are another template)
            ->and($d['categories']['pending']['count'])->toBe(2)
            ->and($d['locales'])->toBe(['es' => 3, 'en' => 1])
            ->and($d['event'])->toBe(['published' => true, 'archived' => false])
            ->and($d['rsvp'])->toBe(['isOpen' => true, 'deadline' => null])
            ->and($d['mode'])->toBe(['automatic' => true, 'reason' => null])
            ->and($d['templates'])->toBe(['es' => ['sendable' => true], 'en' => ['sendable' => true]])
            ->and($d['limits']['perBatchMax'])->toBe(500)
            ->and($d['limits']['dailyRemaining'])->toBeInt()
            ->and($d['limits']['dailyResetsAt'])->toBeString()
            ->and($d['quota'])->toBeNull();
    });

    it('uses the reminder rules for reminder_pending and counts those who already answered as blocked', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event, ['rsvp_status' => 'attending']);
        waGuest($event, ['rsvp_status' => 'pending']);

        $d = $this->actingAs($u['owner'])->getJson("$api/audience?template=reminder_pending")->assertOk()->json('data');

        expect($d['blocked']['already_responded'])->toBe(1)->and($d['categories']['all']['count'])->toBe(1);
    });

    it('defaults to the invitation, rejects unknown templates and has an empty shape without families', function () {
        [$event, $u, $api] = waEvent();

        $d = $this->actingAs($u['owner'])->getJson("$api/audience")->assertOk()->json('data');
        expect($d['template'])->toBe('invitation')->and($d['totalFamilies'])->toBe(0)->and($d['locales'])->toBe([])
            ->and($d['limits']['dailyResetsAt'])->toBeNull();
        $this->actingAs($u['owner'])->getJson("$api/audience?template=nope")->assertUnprocessable();
    });

    it('reports why automatic is off: locked plan, module off, no gateway, template not approved', function () {
        [$e1, $u1, $api1] = waEvent('basic');
        waGuest($e1);
        $this->actingAs($u1['owner'])->getJson("$api1/audience")->assertOk()->assertJsonPath('data.mode', ['automatic' => false, 'reason' => 'module_locked']);

        [$e2, $u2, $api2] = waEvent('premium', false);
        $this->actingAs($u2['owner'])->getJson("$api2/audience")->assertOk()->assertJsonPath('data.mode.reason', 'module_off');

        [$e3, $u3, $api3] = waEvent();
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.token' => null]);
        $this->actingAs($u3['owner'])->getJson("$api3/audience")->assertOk()->assertJsonPath('data.mode.reason', 'whatsapp_unavailable');

        [$e4, $u4, $api4] = waEvent();
        waGuest($e4);
        Template::where('key', 'invitation')->where('locale', 'es')->update(['status' => 'rejected']);
        $this->actingAs($u4['owner'])->getJson("$api4/audience")->assertOk()
            ->assertJsonPath('data.mode.reason', 'template_not_approved')->assertJsonPath('data.templates.es.sendable', false);
    });

    it('says a locale is not sendable when its template does not exist', function () {
        [$event, $u, $api] = waEvent();
        Template::where('key', 'invitation')->where('locale', 'en')->delete();

        $this->actingAs($u['owner'])->getJson("$api/audience")->assertOk()
            ->assertJsonPath('data.templates.en.sendable', false)->assertJsonPath('data.templates.es.sendable', true);
    });

    it('reports an unpublished event and a closed RSVP window', function () {
        [$event, $u, $api] = waEvent();
        $event->update(['status' => 'draft']);
        Rsvp::create(['event_id' => $event->id, 'title' => 'RSVP', 'description' => 'x', 'custom_fields' => '[]', 'confirmation_deadline' => now()->subDay()->toDateTimeString()]);

        $this->actingAs($u['owner'])->getJson("$api/audience")->assertOk()
            ->assertJsonPath('data.event.published', false)->assertJsonPath('data.rsvp.isOpen', false);
    });

    it('does not approve a draft template just by reading', function () {
        [$event, $u, $api] = waEvent();
        Template::where('key', 'invitation')->update(['status' => 'draft']);

        $this->actingAs($u['owner'])->getJson("$api/audience")->assertOk()->assertJsonPath('data.templates.en.sendable', true);

        expect(Template::where('key', 'invitation')->where('status', 'draft')->count())->toBeGreaterThan(0);
    });

    it('works on the free plan, lets a viewer read, and keeps strangers and other events out', function () {
        [$event, $u, $api] = waEvent('basic');
        $this->getJson("$api/audience")->assertUnauthorized();
        $this->actingAs($u['viewer'])->getJson("$api/audience")->assertOk();
        $this->actingAs($u['viewer'])->getJson("$api/recipients")->assertOk();
        $this->actingAs($u['stranger'])->getJson("$api/audience")->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$api/recipients")->assertNotFound();

        // Another event's guests never show up.
        [$other] = waEvent();
        waGuest($other);
        $this->actingAs($u['owner'])->getJson("$api/audience")->assertJsonPath('data.totalFamilies', 0);
        $this->actingAs($u['owner'])->getJson("$api/recipients")->assertJsonPath('meta.total', 0);
    });

    it('gives the dailyResetsAt of the oldest message in the window', function () {
        [$event, $u, $api] = waEvent();
        $g = waGuest($event);
        waMsg($event, $g, 'sent', 'invitation', ['queued_at' => now()->subHours(5)]);

        $at = $this->actingAs($u['owner'])->getJson("$api/audience")->json('data.limits.dailyResetsAt');

        expect(now()->addHours(19)->diffInMinutes(Carbon\Carbon::parse($at)))->toBeLessThan(2);
    });
});

describe('recipients', function () {
    it('lists families with eligibility, reason and delivery, and paginates', function () {
        [$event, $u, $api] = waEvent();
        $ana = waGuest($event, ['name' => 'Ana López']);
        Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => $ana->id]);
        waMsg($event, $ana, 'failed', 'invitation', ['error_code' => '131026', 'error_message' => 'x']);
        waGuest($event, ['name' => 'Beto', 'phone' => '']);
        waGuest($event, ['name' => 'Carla']);

        $r = $this->actingAs($u['owner'])->getJson("$api/recipients?per_page=2&page=1")->assertOk()->json();

        expect($r['meta'])->toBe(['page' => 1, 'perPage' => 2, 'total' => 3])
            ->and($r['data'][0])->toMatchArray(['name' => 'Ana López', 'people' => 3, 'eligible' => true, 'reason' => null, 'locale' => 'es'])
            ->and($r['data'][0]['delivery']['state'])->toBe('failed')
            ->and($r['data'][0]['delivery']['errorCode'])->toBe('131026')
            ->and($r['data'][1])->toMatchArray(['name' => 'Beto', 'eligible' => false, 'reason' => 'no_phone'])
            ->and($r['data'][1]['delivery']['state'])->toBe('no_phone');
        $page2 = $this->actingAs($u['owner'])->getJson("$api/recipients?per_page=2&page=2")->json('data');
        expect($page2)->toHaveCount(1)->and($page2[0]['name'])->toBe('Carla');
    });

    it('gives each family its public invitation link, the same one the roster shows', function () {
        [$event, $u, $api] = waEvent();
        $ana = waGuest($event, ['name' => 'Ana', 'code' => 'ANA123']);
        $beto = waGuest($event, ['name' => 'Beto']);
        $beto->forceFill(['code' => null])->saveQuietly();

        $rows = collect($this->actingAs($u['owner'])->getJson("$api/recipients")->assertOk()->json('data'))->keyBy('name');
        $expected = rtrim((string) config('app.frontend_app.url'), '/') . "/i/{$event->custom_url_slug}/ANA123";

        expect($rows['Ana']['link'])->toBe($expected)->and($rows['Beto']['link'])->toBeNull();
        $this->actingAs($u['viewer'])->getJson("$api/recipients")->assertOk()->assertJsonPath('data.0.link', $expected);
    });

    it('filters by category: unsent, pending, problem and all', function () {
        [$event, $u, $api] = waEvent();
        $sent = waGuest($event, ['name' => 'Enviada']);
        waMsg($event, $sent, 'read');
        $new = waGuest($event, ['name' => 'Nueva']);
        $answered = waGuest($event, ['name' => 'Respondió', 'rsvp_status' => 'attending']);
        waMsg($event, $answered, 'sent');
        waGuest($event, ['name' => 'Sin', 'phone' => '']);
        $failed = waGuest($event, ['name' => 'Fallida']);
        waMsg($event, $failed, 'failed');

        $names = fn (string $q) => collect($this->actingAs($u['owner'])->getJson("$api/recipients?$q")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names('category=unsent'))->toBe(['Fallida', 'Nueva'])
            ->and($names('category=pending'))->toBe(['Enviada'])
            ->and($names('category=problem'))->toBe(['Fallida', 'Sin'])
            ->and($names('category=all'))->toHaveCount(5)
            ->and($names(''))->toHaveCount(5);
    });

    it('searches by name, phone and email without case, and validates the query', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event, ['name' => 'Ana Pérez', 'email' => 'ana@quinvite-test.org', 'phone' => '+13055550101']);
        waGuest($event, ['name' => 'Beto', 'email' => null, 'phone' => '+13055550202']);

        $names = fn (string $q) => collect($this->actingAs($u['owner'])->getJson("$api/recipients?search=" . urlencode($q))->json('data'))->pluck('name')->all();

        expect($names('PÉREZ'))->toBe(['Ana Pérez'])->and($names('quinvite'))->toBe(['Ana Pérez'])->and($names('0202'))->toBe(['Beto'])
            ->and($names('   '))->toHaveCount(2);
        $this->actingAs($u['owner'])->getJson("$api/recipients?category=zzz")->assertUnprocessable();
        $this->actingAs($u['owner'])->getJson("$api/recipients?per_page=101")->assertUnprocessable();
        $this->actingAs($u['owner'])->getJson("$api/recipients?template=nope")->assertUnprocessable();
    });

    it('marks an opted out family as not eligible with no way to force it', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event, ['name' => 'Baja', 'phone' => '+1 305 555 0777']);
        OptOut::add('+13055550777');

        $row = $this->actingAs($u['owner'])->getJson("$api/recipients")->json('data.0');

        expect($row['eligible'])->toBeFalse()->and($row['reason'])->toBe('opted_out')->and($row['delivery']['state'])->toBe('none');
    });
});

describe('send guards', function () {
    it('refuses to send while the event is not published, dry run included', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        $event->update(['status' => 'draft']);

        foreach ([false, true] as $dry) {
            $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'dry_run' => $dry])
                ->assertStatus(409)->assertJsonPath('code', 'event_not_published');
        }
        expect(Message::count())->toBe(0);
    });

    it('blocks invitation and reminder when the RSVP window is closed but lets the save the date go', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        Rsvp::create(['event_id' => $event->id, 'title' => 'RSVP', 'description' => 'x', 'custom_fields' => '[]', 'confirmation_deadline' => now()->subDay()->toDateTimeString()]);

        foreach (['invitation', 'reminder_pending'] as $t) {
            $this->actingAs($u['owner'])->postJson("$api/send", ['template' => $t, 'audience' => 'all'])->assertStatus(409)->assertJsonPath('code', 'rsvp_closed');
        }
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'all'])->assertStatus(202);
    });

    it('still answers event_archived first for an archived account', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        $event->forceFill(['status' => 'archived', 'purge_after' => now()->addDays(30)])->save();

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(409)->assertJsonPath('code', 'event_archived');
    });

    it('keeps sending as before to a published event and skipping for the same reasons', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event, ['name' => 'Ok']);
        waGuest($event, ['name' => 'Sin', 'phone' => '']);
        $recent = waGuest($event, ['name' => 'Reciente']);
        waMsg($event, $recent, 'sent', 'invitation', ['queued_at' => now()->subHour()]);

        $r = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202)->json('data');

        expect($r['queued'])->toBe(1)->and(collect($r['skipped'])->pluck('reason')->sort()->values()->all())->toBe(['no_phone', 'recently_sent']);
        // The audience predicts exactly that: one eligible family.
        $this->actingAs($u['owner'])->getJson("$api/audience")->assertJsonPath('data.blocked.recently_sent', 2);
    });
});

describe('manual tracking', function () {
    it('marks a family as shared, idempotently, and undoes it', function () {
        [$event, $u, $api] = waEvent('basic');
        $g = waGuest($event);
        $roster = "/api/v1/app/event/{$event->id}/roster";

        $first = $this->actingAs($u['owner'])->postJson("$roster/{$g->id}/shared")->assertOk()->json('data');
        expect($first['delivery']['state'])->toBe('manual')->and($first['delivery']['sharedAt'])->toBeString();

        $this->travel(2)->hours();
        $again = $this->actingAs($u['owner'])->postJson("$roster/{$g->id}/shared")->assertOk()->json('data');
        expect($again['delivery']['sharedAt'])->toBe($first['delivery']['sharedAt']);

        $undone = $this->actingAs($u['owner'])->deleteJson("$roster/{$g->id}/shared")->assertOk()->json('data');
        expect($undone['delivery']['state'])->toBe('none')->and($undone['delivery']['sharedAt'])->toBeNull();
        $this->actingAs($u['owner'])->deleteJson("$roster/{$g->id}/shared")->assertOk();
        expect($g->fresh()->invitation_shared_at)->toBeNull();
    });

    it('lets owner and editor mark, but not a viewer, a stranger or another event', function () {
        [$event, $u, $api] = waEvent('basic');
        $g = waGuest($event);
        $roster = "/api/v1/app/event/{$event->id}/roster";

        $this->postJson("$roster/{$g->id}/shared")->assertUnauthorized();
        $this->actingAs($u['viewer'])->postJson("$roster/{$g->id}/shared")->assertForbidden();
        $this->actingAs($u['viewer'])->deleteJson("$roster/{$g->id}/shared")->assertForbidden();
        $this->actingAs($u['stranger'])->postJson("$roster/{$g->id}/shared")->assertNotFound();

        $editor = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $editor->id, 'role_id' => Role::where('name', 'editor')->value('id')]);
        $this->actingAs($editor)->postJson("$roster/{$g->id}/shared")->assertOk();

        [$other] = waEvent('basic');
        $foreign = waGuest($other);
        $this->actingAs($u['owner'])->postJson("$roster/{$foreign->id}/shared")->assertNotFound();
    });

    it('only marks main guests', function () {
        [$event, $u] = waEvent('basic');
        $main = waGuest($event);
        $comp = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $main->id]);
        $roster = "/api/v1/app/event/{$event->id}/roster";

        $this->actingAs($u['owner'])->postJson("$roster/{$comp->id}/shared")->assertUnprocessable();
        $this->actingAs($u['owner'])->deleteJson("$roster/{$comp->id}/shared")->assertUnprocessable();
    });
});

describe('roster delivery', function () {
    it('puts the delivery state in each row and the counters in counts.delivery', function () {
        [$event, $u] = waEvent('basic');
        $roster = "/api/v1/app/event/{$event->id}/roster";
        $none = waGuest($event, ['name' => 'A none']);
        $read = waGuest($event, ['name' => 'B read']);
        waMsg($event, $read, 'sent');
        waMsg($event, $read, 'read', 'invitation', ['read_at' => now()->subMinute(), 'retry_count' => 2]);
        $failed = waGuest($event, ['name' => 'C failed']);
        waMsg($event, $failed, 'failed', 'invitation', ['error_code' => '131026', 'error_message' => 'no', 'failed_at' => now()]);
        waGuest($event, ['name' => 'D nophone', 'phone' => '']);
        $stop = waGuest($event, ['name' => 'E stop', 'phone' => '+1 305 555 0777']);
        OptOut::add('+13055550777');
        $stop->forceFill(['whatsapp_opted_out_at' => now()])->save();
        $manual = waGuest($event, ['name' => 'F manual']);
        $manual->forceFill(['invitation_shared_at' => now()])->save();
        $queued = waGuest($event, ['name' => 'G queued']);
        waMsg($event, $queued, 'queued');
        $sent = waGuest($event, ['name' => 'H sent']);
        waMsg($event, $sent, 'sent', 'invitation', ['sent_at' => now()]);
        $delivered = waGuest($event, ['name' => 'I delivered']);
        waMsg($event, $delivered, 'delivered', 'invitation', ['delivered_at' => now()]);

        $r = $this->actingAs($u['owner'])->getJson("$roster?perPage=100")->assertOk()->json();
        $by = collect($r['data'])->keyBy('name');

        expect($by['A none']['delivery']['state'])->toBe('none')
            ->and($by['B read']['delivery'])->toMatchArray(['state' => 'read', 'retryCount' => 2])
            ->and($by['B read']['delivery']['lastMessageId'])->toBeInt()
            ->and($by['C failed']['delivery'])->toMatchArray(['state' => 'failed', 'errorCode' => '131026', 'errorMessage' => 'no'])
            ->and($by['D nophone']['delivery']['state'])->toBe('no_phone')
            ->and($by['E stop']['delivery']['state'])->toBe('opted_out')
            ->and($by['F manual']['delivery']['state'])->toBe('manual')
            ->and($by['G queued']['delivery']['state'])->toBe('queued')
            ->and($by['H sent']['delivery']['state'])->toBe('sent')
            ->and($by['I delivered']['delivery']['state'])->toBe('delivered')
            ->and($r['counts']['delivery'])->toBe(['unsent' => 1, 'queued' => 1, 'sent' => 1, 'delivered' => 1, 'read' => 1, 'failed' => 1, 'noPhone' => 1, 'optedOut' => 1, 'shared' => 1]);
    });

    it('filters by the unsent, sent and problem chips and ignores unknown values', function () {
        [$event, $u] = waEvent('basic');
        $roster = "/api/v1/app/event/{$event->id}/roster";
        waGuest($event, ['name' => 'Nada']);
        $s = waGuest($event, ['name' => 'Enviada']);
        waMsg($event, $s, 'delivered');
        $m = waGuest($event, ['name' => 'Manual']);
        $m->forceFill(['invitation_shared_at' => now()])->save();
        $f = waGuest($event, ['name' => 'Falla']);
        waMsg($event, $f, 'failed');
        waGuest($event, ['name' => 'Sin', 'phone' => '']);

        $names = fn (string $q) => collect($this->actingAs($u['owner'])->getJson("$roster?delivery=$q")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names('unsent'))->toBe(['Nada'])
            ->and($names('sent'))->toBe(['Enviada', 'Manual'])
            ->and($names('problem'))->toBe(['Falla', 'Sin'])
            ->and($names('zzz'))->toHaveCount(5);
    });

    it('is stable in queries as the list grows (no N+1 per row)', function () {
        [$event, $u] = waEvent('basic');
        $roster = "/api/v1/app/event/{$event->id}/roster";
        $count = function () use ($roster, $u) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($u['owner'])->getJson("$roster?perPage=100")->assertOk();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 3) as $i) {
            waMsg($event, waGuest($event), 'sent');
        }
        $count(); // warm the caches (modules, permissions)
        $small = $count();
        foreach (range(1, 20) as $i) {
            waMsg($event, waGuest($event), 'read');
        }

        expect($count())->toBe($small);
    });

    it('returns the delivery of the family in the other roster responses too', function () {
        [$event, $u] = waEvent('basic');
        $roster = "/api/v1/app/event/{$event->id}/roster";
        $g = waGuest($event);
        waMsg($event, $g, 'sent');

        $this->actingAs($u['owner'])->patchJson("$roster/{$g->id}/locale", ['locale' => 'en'])->assertOk()->assertJsonPath('data.delivery.state', 'sent');
        $comp = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $g->id]);
        $this->actingAs($u['owner'])->putJson("$roster/{$comp->id}", ['name' => 'Nuevo nombre'])->assertOk()->assertJsonPath('data.delivery.state', 'sent');
    });

    it('counts a family as pending when the main guest has not answered, not for a pending companion', function () {
        [$event, $u] = waEvent('basic');
        $roster = "/api/v1/app/event/{$event->id}/roster";
        $a = waGuest($event, ['name' => 'Ana', 'rsvp_status' => 'attending']);
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $a->id, 'rsvp_status' => 'pending']);
        waGuest($event, ['name' => 'Beto', 'rsvp_status' => 'pending']);

        $names = collect($this->actingAs($u['owner'])->getJson("$roster?status=pending")->json('data'))->pluck('name')->all();

        expect($names)->toBe(['Beto']);
    });
});

describe('migration', function () {
    it('adds invitation_shared_at, can run twice and rolls back without touching rows', function () {
        $migration = require database_path('migrations/2027_07_10_000001_add_invitation_shared_at_to_guests.php');
        expect(Schema::hasColumn('guests', 'invitation_shared_at'))->toBeTrue();

        $migration->up();
        expect(Schema::hasColumn('guests', 'invitation_shared_at'))->toBeTrue();

        [$event] = waEvent();
        $g = waGuest($event, ['name' => 'Persist']);

        $migration->down();
        expect(Schema::hasColumn('guests', 'invitation_shared_at'))->toBeFalse();
        $migration->down();

        $migration->up();
        expect(Schema::hasColumn('guests', 'invitation_shared_at'))->toBeTrue()->and($g->fresh()->name)->toBe('Persist')->and($g->fresh()->invitation_shared_at)->toBeNull();
    });
});
