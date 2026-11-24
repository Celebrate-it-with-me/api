<?php

use App\Models\EventActivity;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** A published quinceañera on the premium plan, an owner, a main guest (AB1234) with two companions. */
function feedEvent(string $slug = 'xv-de-valentina', string $guestCode = 'AB1234'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'custom_url_slug' => $slug,
        'status' => 'published',
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    $guest = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Rosa Fernández', 'code' => $guestCode, 'rsvp_status' => 'pending', 'parent_id' => null]);
    $companions = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => $guest->id, 'rsvp_status' => 'pending']);

    return [$event, $owner, $guest, $companions];
}

function feedRsvp($test, string $slug, string $code, string $status, array $companionIds = [])
{
    return $test->postJson("/api/v1/app/invitation/{$slug}/{$code}/rsvp", [
        'guest' => ['rsvpStatus' => $status],
        'companions' => array_map(fn ($id) => ['id' => $id, 'rsvpStatus' => $status], $companionIds),
    ]);
}

function feedOf(Events $event): Collection
{
    return EventActivity::where('event_id', $event->id)->orderBy('id')->get();
}

describe('guest answers', function () {
    it('logs a confirmation with the companions who come', function () {
        [$event, $owner, $guest, $companions] = feedEvent();

        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending', $companions->pluck('id')->all())->assertOk();

        $activity = feedOf($event)->sole();
        expect($activity->type)->toBe('guest_confirmed')
            ->and($activity->actor_type)->toBe('guest')
            ->and($activity->actor_id)->toBe($guest->id)
            ->and($activity->payload)->toMatchArray(['name' => 'Rosa Fernández', 'companions' => 2]);

        app()->setLocale('es');
        expect($activity->message)->toBe('El invitado Rosa Fernández confirmó su asistencia con 2 acompañantes');
        app()->setLocale('en');
        expect($activity->message)->toBe('Guest Rosa Fernández has confirmed their attendance with 2 companions');
    });

    it('uses the plain sentence when nobody comes along', function () {
        [$event] = feedEvent();

        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();

        app()->setLocale('en');
        expect(feedOf($event)->sole()->message)->toBe('Guest Rosa Fernández has confirmed their attendance.');
    });

    it('logs a decline', function () {
        [$event] = feedEvent();

        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'not-attending')->assertOk();

        $activity = feedOf($event)->sole();
        expect($activity->type)->toBe('guest_declined');
        app()->setLocale('es');
        expect($activity->message)->toBe('El invitado Rosa Fernández declinó la invitación.');
        expect($activity->icon)->toBe('user-x')->and($activity->color)->toBe('red');
    });

    it('logs a change of answer later on, with where it went', function () {
        [$event, , $guest] = feedEvent();
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();
        // The first answer is old news: the guest changes her mind another day.
        EventActivity::query()->update(['created_at' => now()->subHour()]);

        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'not-attending')->assertOk();

        $last = feedOf($event)->last();
        expect($last->type)->toBe('guest_rsvp_changed')
            ->and($last->payload)->toMatchArray(['from' => 'attending', 'to' => 'not-attending']);
        app()->setLocale('es');
        expect($last->message)->toBe('El invitado Rosa Fernández cambió su respuesta a «no asiste».');
        app()->setLocale('en');
        expect($last->message)->toBe('Guest Rosa Fernández changed their answer to "not attending".');
    });

    it('does not repeat itself: the same answer twice, or the same entry within 60 seconds', function () {
        [$event] = feedEvent();

        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk(); // status did not move
        expect(feedOf($event))->toHaveCount(1);

        // Flip-flopping inside a minute collapses the "changed" entries into one.
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'not-attending')->assertOk();
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();
        expect(feedOf($event)->pluck('type')->all())->toBe(['guest_confirmed', 'guest_rsvp_changed']);
    });

    it('logs nothing when the answer is rejected', function () {
        [$event] = feedEvent();

        $this->postJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234/rsvp", ['guest' => ['rsvpStatus' => 'maybe']])->assertUnprocessable();

        expect(feedOf($event))->toHaveCount(0);
    });
});

describe('first opening', function () {
    it('logs the first opening of a guest and never the next ones', function () {
        [$event, , $guest] = feedEvent();

        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234")->assertOk();
        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234")->assertOk();

        $activity = feedOf($event)->sole();
        expect($activity->type)->toBe('invitation_opened')->and($activity->payload)->toBe(['name' => 'Rosa Fernández'])
            ->and($guest->fresh()->invitation_opened_at)->not->toBeNull();
        app()->setLocale('es');
        expect($activity->message)->toBe('El invitado Rosa Fernández abrió su invitación.');
    });

    it('writes nothing on a revalidation (304) nor on a warm repeat visit', function () {
        [$event] = feedEvent();
        $url = "/api/v1/app/invitation/{$event->custom_url_slug}/AB1234";
        $etag = $this->getJson($url)->assertOk()->headers->get('ETag');

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->getJson($url, ['If-None-Match' => $etag])->assertStatus(304);
        $this->getJson($url)->assertOk();

        expect($writes)->toBe([])->and(feedOf($event))->toHaveCount(1);
    });

    it('does not count a wrong code, an unpublished event or the calendar file as an opening', function () {
        [$event] = feedEvent();

        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/NOPE99")->assertNotFound();
        $this->get("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234/calendar.ics")->assertOk();
        $event->update(['status' => 'draft']);
        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234")->assertNotFound();

        expect(feedOf($event))->toHaveCount(0);
    });

    it('counts one opening per guest', function () {
        [$event] = feedEvent();
        Guest::factory()->create(['event_id' => $event->id, 'name' => 'Luis', 'code' => 'CD5678', 'parent_id' => null]);

        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234")->assertOk();
        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/CD5678")->assertOk();

        expect(feedOf($event)->pluck('payload.name')->all())->toBe(['Rosa Fernández', 'Luis']);
    });
});

describe('guest comments and songs', function () {
    it('logs a comment and a suggested song through their active modules', function () {
        [$event] = feedEvent();
        $base = "/api/v1/app/template/event/{$event->id}";

        $this->postJson("{$base}/comments", ['accessCode' => 'AB1234', 'comment' => 'Qué lindo!'])->assertCreated();
        $this->postJson("{$base}/suggest-music", [
            'accessCode' => 'AB1234', 'title' => 'Vivir mi vida', 'artist' => 'Marc Anthony', 'album' => 'A', 'platformId' => 'sp1', 'thumbnailUrl' => 'https://x.test/a.jpg',
        ])->assertCreated();

        $feed = feedOf($event);
        expect($feed->pluck('type')->all())->toBe(['comment_added', 'music_suggested']);
        app()->setLocale('es');
        expect($feed[0]->message)->toBe('El invitado Rosa Fernández dejó un comentario.')
            ->and($feed[1]->message)->toBe('El invitado Rosa Fernández sugirió la canción «Vivir mi vida».');
        // The text of the comment stays out of the feed.
        expect(json_encode($feed[0]->payload))->not->toContain('lindo');
    });

    it('logs nothing when the module is locked', function () {
        [$event] = feedEvent();
        $event->update(['event_plan_id' => EventPlan::where('slug', 'basic')->value('id')]);
        $event->eventPlan->modules()->detach();

        $this->postJson("/api/v1/app/template/event/{$event->id}/comments", ['accessCode' => 'AB1234', 'comment' => 'Hola'])->assertStatus(403);

        expect(feedOf($event))->toHaveCount(0);
    });
});

describe('the dashboard feed', function () {
    it('keeps its shape and its limit of 10, newest first', function () {
        [$event, $owner] = feedEvent();
        foreach (range(1, 12) as $i) {
            EventActivity::logActivity($event->id, 'invitation_opened', null, null, ['name' => "G{$i}"]);
        }

        $data = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/dashboard-logs")->assertOk()->json('data');

        expect($data)->toHaveCount(10)
            ->and(array_keys($data[0]))->toBe(['id', 'type', 'message', 'meta', 'icon', 'color', 'createdAt'])
            ->and($data[0]['message'])->toContain('G12');
    });

    it('shows the new entries only to the people of that event', function () {
        [$event, $owner] = feedEvent();
        [$other, $otherOwner] = feedEvent('otro-evento', 'ZZ9999');
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();
        feedRsvp($this, $other->custom_url_slug, 'ZZ9999', 'not-attending')->assertOk();

        $mine = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/dashboard-logs")->assertOk()->json('data');
        expect($mine)->toHaveCount(1)->and($mine[0]['type'])->toBe('guest_confirmed');
        $this->actingAs($otherOwner)->getJson("/api/v1/app/event/{$event->id}/dashboard-logs")->assertNotFound();
    });

    it('is not reachable by a guest, who has no session', function () {
        [$event] = feedEvent();

        $this->getJson("/api/v1/app/event/{$event->id}/dashboard-logs?accessCode=AB1234")->assertUnauthorized();
    });

    it('stores no IP, user agent or contact data of the guest', function () {
        [$event] = feedEvent();
        $this->withHeaders(['User-Agent' => 'SecretAgent/1.0'])->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/AB1234")->assertOk();
        feedRsvp($this, $event->custom_url_slug, 'AB1234', 'attending')->assertOk();

        $stored = json_encode(feedOf($event)->map->getAttributes()->all());
        expect($stored)->not->toContain('SecretAgent')->not->toContain('127.0.0.1')
            ->and(feedOf($event)->pluck('payload')->flatMap(fn ($p) => array_keys($p))->unique()->sort()->values()->all())
            ->toBe(['companions', 'name']);
    });
});

describe('messages of staff-side entries', function () {
    it('words the template change and its undo', function () {
        $owner = User::factory()->create(['name' => 'Marisol']);
        $event = Events::factory()->create(['event_type_id' => EventType::where('slug', 'quinceanera')->value('id')]);
        $changed = EventActivity::logActivity($event->id, 'invitation_template_changed', $owner, $event, ['from' => 1, 'to' => 2, 'template' => 'Jardín']);
        $undone = EventActivity::logActivity($event->id, 'invitation_template_undone', $owner, $event, ['from' => 2, 'to' => 1, 'template' => 'Clásica']);

        app()->setLocale('es');
        expect($changed->message)->toBe('La plantilla de la invitación cambió a Jardín, por Marisol')
            ->and($undone->message)->toBe('El cambio de plantilla se deshizo, por Marisol');
        app()->setLocale('en');
        expect($changed->message)->toBe('Invitation template changed to Jardín by Marisol');
    });

    it('logs a manually added guest with the existing sentence', function () {
        [$event, $owner] = feedEvent();

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", ['guest' => ['name' => 'Tía Carmen', 'email' => 'carmen@example.com']])->assertSuccessful();

        $entry = feedOf($event)->firstWhere('type', 'guest_created');
        expect($entry)->not->toBeNull();
        app()->setLocale('es');
        expect($entry->message)->toBe("El invitado Tía Carmen fue agregado por {$owner->name}");
    });
});

describe('the readable feed', function () {
    it('gives each entry a stable type, a sentence in the user language and a safe meta', function () {
        [$event, $owner] = feedEvent();
        EventActivity::logActivity($event->id, 'guests_imported', $owner, $event, ['count' => 12, 'families' => 5]);
        EventActivity::logActivity($event->id, 'guest_confirmed', null, null, ['name' => 'Rosa', 'companions' => 2, 'email' => 'r@x.test', 'phone' => '+13055550100', 'code' => 'AB1234']);
        EventActivity::logActivity($event->id, 'invitation_cover_uploaded', $owner, $event, ['path' => 'secret/p.jpg']);

        $url = "/api/v1/app/event/{$event->id}/dashboard-logs";
        $es = $this->actingAs($owner)->getJson($url, ['Accept-Language' => 'es'])->assertOk()->json('data');
        $en = $this->actingAs($owner)->getJson($url, ['Accept-Language' => 'en'])->assertOk()->json('data');

        $byType = fn (array $rows, string $t) => collect($rows)->firstWhere('type', $t);
        expect($byType($es, 'guests_imported')['meta'])->toBe(['module' => 'guests', 'name' => null, 'count' => 12])
            ->and($byType($es, 'guest_confirmed')['meta'])->toBe(['module' => 'rsvp', 'name' => 'Rosa', 'count' => 2])
            ->and($byType($es, 'invitation_cover_uploaded')['message'])->toContain('portada')
            ->and($byType($en, 'invitation_cover_uploaded')['message'])->toContain('Cover photo')
            ->and($byType($en, 'guest_confirmed')['message'])->toContain('Rosa')
            ->and(json_encode($es))->not->toContain('r@x.test')->not->toContain('+13055550100')->not->toContain('AB1234')->not->toContain('secret/p.jpg');
    });

    it('leaves out the types with no readable sentence instead of "Activity logged"', function () {
        [$event, $owner] = feedEvent();
        EventActivity::logActivity($event->id, 'invitation_field_changed', $owner, $event, ['field' => 'x']);
        EventActivity::logActivity($event->id, 'something_unknown', $owner, $event, []);
        EventActivity::logActivity($event->id, 'comment_added', null, null, ['name' => 'Ana']);

        $data = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/dashboard-logs")->assertOk()->json('data');

        expect(collect($data)->pluck('type')->all())->toBe(['comment_added'])
            ->and($data[0]['meta']['module'])->toBe('comments');
        expect((new EventActivity(['type' => 'x']))->meta)->toBe(['module' => null, 'name' => null, 'count' => null]);
    });
});
