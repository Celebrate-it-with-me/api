<?php

use App\Models\EventLocation;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\Timeline;
use App\Support\InvitationCache;
use Database\Seeders\EventPlanSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const CACHE_URL = '/api/v1/app/invitation';

/** @return array{0: Events, 1: Guest, 2: Guest} an event with two main guests */
function cachedInvitationFixture(string $slug = 'xv-de-valentina', array $event = []): array
{
    $type = EventType::query()->firstOrCreate(['slug' => 'quinceanera'], ['name' => 'Quinceanera']);
    test()->seed([InvitationTemplateSeeder::class, EventPlanSeeder::class, ModuleSeeder::class]);

    $event = Events::factory()->create(array_merge([
        'event_type_id' => $type->id, 'event_name' => 'Los XV de Valentina', 'custom_url_slug' => $slug,
        'status' => 'published', 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ], $event));

    $rosa = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Rosa Fernández', 'code' => 'AB1234', 'rsvp_status' => 'pending', 'parent_id' => null]);
    $luis = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Luis Pérez', 'code' => 'CD5678', 'rsvp_status' => 'pending', 'parent_id' => null]);

    return [$event, $rosa, $luis];
}

function invitationQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

describe('shared payload cache', function () {
    it('stores the guest-independent part once and serves it to every guest of the event', function () {
        [$event] = cachedInvitationFixture();
        $key = InvitationCache::key($event->id, 'es');

        expect(Cache::has($key))->toBeFalse();
        $first = invitationQueries(fn () => $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk());
        expect(Cache::has($key))->toBeTrue();

        $second = invitationQueries(fn () => $this->getJson(CACHE_URL . '/xv-de-valentina/CD5678')->assertOk());

        expect($second)->toBeLessThan($first);
    });

    it('returns the same payload with a warm cache as without it', function () {
        [$event] = cachedInvitationFixture();
        Timeline::create(['event_id' => $event->id, 'title' => 'Misa', 'type' => 'ceremony', 'start_time' => '2027-03-20 16:00:00']);

        $cold = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->getContent();
        $warm = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->getContent();
        Cache::flush();
        $rebuilt = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->getContent();

        expect($warm)->toBe($cold)->and($rebuilt)->toBe($cold);
    });

    it('never mixes guests: the name and the RSVP stay their own', function () {
        [$event, $rosa, $luis] = cachedInvitationFixture();
        $rosa->forceFill(['rsvp_status' => 'attending', 'rsvp_note' => 'Vamos todos'])->save();

        $a = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        $b = $this->getJson(CACHE_URL . '/xv-de-valentina/CD5678')->assertOk();

        $rsvp = fn ($r) => collect($r->json('data.sections'))->firstWhere('type', 'rsvp')['data'];

        expect($a->json('data.guest.name'))->toBe('Rosa Fernández')
            ->and($b->json('data.guest.name'))->toBe('Luis Pérez')
            ->and($rsvp($a)['guest']['status'])->toBe('attending')
            ->and($rsvp($a)['note'])->toBe('Vamos todos')
            ->and($rsvp($b)['guest']['status'])->toBe('pending')
            ->and($rsvp($b)['note'])->toBeNull();
        expect($b->getContent())->not->toContain('Rosa')->not->toContain('Vamos todos');
    });

    it('keeps one entry per event and per locale', function () {
        [$event] = cachedInvitationFixture();
        $other = Events::factory()->create([
            'event_type_id' => $event->event_type_id, 'event_name' => 'Boda de Ana', 'custom_url_slug' => 'boda-de-ana',
            'status' => 'published', 'event_plan_id' => $event->event_plan_id,
        ]);
        Guest::factory()->create(['event_id' => $other->id, 'name' => 'Invitada Ana', 'code' => 'AN0001', 'parent_id' => null]);

        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        $this->getJson(CACHE_URL . '/boda-de-ana/AN0001')->assertOk()->assertJsonPath('data.event.name', 'Boda de Ana');
        $en = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234?lang=en')->assertOk();
        $es = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();

        expect($en->json('data.locale'))->toBe('en')->and($es->json('data.locale'))->toBe('es')
            ->and($es->json('data.event.name'))->toBe('Los XV de Valentina')
            ->and(InvitationCache::key($event->id, 'es'))->not->toBe(InvitationCache::key($other->id, 'es'))
            ->and(InvitationCache::key($event->id, 'es'))->not->toBe(InvitationCache::key($event->id, 'en'));
    });

    it('does not serve an unpublished event from cache, and serves it again once republished', function () {
        [$event] = cachedInvitationFixture();

        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        expect(Cache::has(InvitationCache::key($event->id, 'es')))->toBeTrue();

        $event->update(['status' => 'draft']);
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertNotFound();

        $event->update(['status' => 'published']);
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();

        $event->update(['status' => 'archived']);
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertNotFound();
    });
});

describe('invalidation', function () {
    it('shows an edited event name at once', function () {
        [$event] = cachedInvitationFixture();
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk()->assertJsonPath('data.event.name', 'Los XV de Valentina');

        $event->update(['event_name' => 'Los XV de Vale']);

        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk()->assertJsonPath('data.event.name', 'Los XV de Vale');
    });

    it('shows a new location and a new moment of the program', function () {
        [$event] = cachedInvitationFixture();
        $types = fn () => collect($this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->json('data.sections'))->pluck('type')->all();
        expect($types())->not->toContain('locations')->not->toContain('itinerary');

        EventLocation::query()->create([
            'event_id' => $event->id, 'name' => 'Salón Jardín', 'address' => '1200 NW 7th Street',
            'city' => 'Miami', 'state' => 'FL', 'zip_code' => '33125', 'country' => 'US', 'is_default' => true,
        ]);
        expect($types())->toContain('locations');

        Timeline::create(['event_id' => $event->id, 'title' => 'Misa', 'type' => 'ceremony', 'start_time' => '2027-03-20 16:00:00']);
        expect($types())->toContain('itinerary');
    });

    it('follows a change of plan (watermark) and of template configuration', function () {
        [$event] = cachedInvitationFixture();
        $watermark = fn () => $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->json('data.branding.watermark');
        expect($watermark())->toBeFalse();

        $event->update(['event_plan_id' => EventPlan::where('slug', 'basic')->value('id')]);
        expect($watermark())->toBeTrue();

        $event->update(['template_config' => ['theme' => ['preset' => 'sage']]]);
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertJsonPath('data.theme.preset', 'sage');
    });

    it('is renewed for every event when a template changes', function () {
        [$event] = cachedInvitationFixture();
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        $before = InvitationCache::key($event->id, 'es');

        InvitationTemplate::query()->first()->touch();

        expect(InvitationCache::key($event->id, 'es'))->not->toBe($before);
    });

    it('does not renew the shared payload when a guest answers the RSVP', function () {
        [$event, $rosa] = cachedInvitationFixture();
        $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        $key = InvitationCache::key($event->id, 'es');

        $this->postJson(CACHE_URL . '/xv-de-valentina/AB1234/rsvp', ['guest' => ['rsvpStatus' => 'attending']])->assertOk();

        expect(InvitationCache::key($event->id, 'es'))->toBe($key)->and(Cache::has($key))->toBeTrue();
        $answer = collect($this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->json('data.sections'))->firstWhere('type', 'rsvp')['data'];
        expect($answer['guest']['status'])->toBe('attending');
    });
});

describe('ETag', function () {
    it('answers 304 to the same tag and a full 200 once the payload changes', function () {
        [$event] = cachedInvitationFixture();

        $first = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
        $etag = $first->headers->get('ETag');
        expect($etag)->toStartWith('W/"');

        $this->withHeaders(['If-None-Match' => $etag])->getJson(CACHE_URL . '/xv-de-valentina/AB1234')
            ->assertStatus(304)->assertHeader('ETag', $etag);

        $event->update(['event_name' => 'Otro nombre']);
        $this->withHeaders(['If-None-Match' => $etag])->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->assertOk();
    });

    it('never matches the tag of another guest', function () {
        cachedInvitationFixture();

        $rosa = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->headers->get('ETag');
        $this->withHeaders(['If-None-Match' => $rosa])->getJson(CACHE_URL . '/xv-de-valentina/CD5678')->assertOk();
    });

    it('lets the browser keep it but forbids shared caches', function () {
        cachedInvitationFixture();

        $header = $this->getJson(CACHE_URL . '/xv-de-valentina/AB1234')->headers->get('Cache-Control');

        expect($header)->toContain('private')->toContain('no-cache')->toContain('must-revalidate')->not->toContain('public');
    });
});
