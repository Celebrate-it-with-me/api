<?php

use App\Models\EventLocation;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\GuestRsvpLog;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const INVITATION_URL = '/api/v1/app/invitation';

/** An invitation-ready event of the given type with a main guest and two companions. */
function invitationFixture(string $typeSlug = 'quinceanera', array $event = []): array
{
    $type = EventType::factory()->create(['slug' => $typeSlug, 'name' => ucfirst($typeSlug)]);
    test()->seed(InvitationTemplateSeeder::class);

    $event = Events::factory()->create(array_merge([
        'event_type_id' => $type->id,
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => 'xv-de-valentina',
        'status' => 'published',
    ], $event));

    $main = Guest::factory()->create([
        'event_id' => $event->id, 'name' => 'Rosa Fernández', 'code' => 'AB1234',
        'rsvp_status' => 'pending', 'parent_id' => null,
    ]);
    $companions = Guest::factory()->count(2)->create([
        'event_id' => $event->id, 'parent_id' => $main->id, 'rsvp_status' => 'pending',
    ]);

    return [$event, $main, $companions];
}

describe('GET invitation', function () {
    it('returns the sections, theme and only the guest data', function () {
        [$event, $main] = invitationFixture();
        $other = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Persona Ajena', 'code' => 'ZZ9999', 'parent_id' => null]);

        $response = $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->assertOk();

        expect($response->json('data.guest'))->toBe(['name' => 'Rosa Fernández'])
            ->and($response->json('data.locale'))->toBe('es')
            ->and($response->json('data.theme.name'))->toBe('classic')
            ->and($response->json('data.theme.preset'))->toBe('rose')
            ->and($response->json('data.branding.watermark'))->toBeTrue()
            ->and(collect($response->json('data.sections'))->pluck('type')->all())
            ->toBe(['cover', 'rsvp', 'closing']); // no venue and no dress code yet

        expect($response->getContent())
            ->not->toContain('Persona Ajena')
            ->not->toContain($main->email)
            ->not->toContain((string) $main->phone);
    });

    it('uses the wedding copy for weddings and the requested language', function () {
        invitationFixture('wedding');

        $es = $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->assertOk();
        $en = $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234?lang=en')->assertOk();

        expect($es->json('data.sections.0.title'))->toBe('Te invitamos a celebrar')
            ->and($es->json('data.sections.0.text'))->toContain('Queremos')
            ->and($en->json('data.sections.0.title'))->toBe('Join us to celebrate')
            ->and($en->json('data.locale'))->toBe('en');
    });

    it('falls back to the guest and then the event locale', function () {
        [$event, $main] = invitationFixture();
        $main->update(['locale' => 'en']);

        expect($this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->json('data.locale'))->toBe('en');
        expect($this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234?lang=fr')->json('data.locale'))->toBe('en');
    });

    it('includes locations and dress code when the event has them', function () {
        [$event] = invitationFixture();
        EventLocation::query()->create([
            'event_id' => $event->id, 'name' => 'Salón Jardín', 'address' => '1200 NW 7th Street',
            'city' => 'Miami', 'state' => 'FL', 'zip_code' => '33125', 'country' => 'US', 'is_default' => true,
        ]);
        DB::table('dress_codes')->insert([
            'event_id' => $event->id, 'dress_code_type' => 'formal', 'description' => 'Elegante',
            'reserved_colors' => json_encode(['#ffffff']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sections = collect($this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->json('data.sections'))->keyBy('type');

        expect($sections->keys()->all())->toBe(['cover', 'locations', 'dress_code', 'rsvp', 'closing'])
            ->and($sections['locations']['data']['items'][0]['name'])->toBe('Salón Jardín')
            ->and($sections['locations']['data']['items'][0]['mapsUrl'])->toStartWith('https://www.google.com/maps/search/?api=1&query=')
            ->and($sections['dress_code']['data']['reservedColors'])->toBe(['#ffffff'])
            ->and($sections['dress_code']['data']['suggestedColors'])->toBe([])
            ->and($sections['dress_code']['data']['images'])->toBe([]);
    });

    it('lets the organizer reorder, rename and hide sections', function () {
        [$event] = invitationFixture();
        $event->update(['template_config' => [
            'sections' => [
                ['type' => 'closing', 'order' => 0, 'title' => 'Hasta pronto'],
                ['type' => 'rsvp', 'enabled' => false],
            ],
            'theme' => ['preset' => 'sage'],
        ]]);

        $data = $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->json('data');

        expect(collect($data['sections'])->pluck('type')->all())->toBe(['closing', 'cover'])
            ->and($data['sections'][0]['title'])->toBe('Hasta pronto')
            ->and($data['theme']['preset'])->toBe('sage')
            ->and($data['theme']['colors']['primary'])->toBe('#5f7a5b');
    });

    it('answers 404 for a wrong code, a companion code, another event and a draft', function () {
        [$event, $main, $companions] = invitationFixture();
        $otherEvent = Events::factory()->create(['custom_url_slug' => 'otro-evento', 'status' => 'published']);

        $this->getJson(INVITATION_URL . '/xv-de-valentina/NOPE00')->assertNotFound();
        $this->getJson(INVITATION_URL . '/xv-de-valentina/' . $companions[0]->code)->assertNotFound();
        $this->getJson(INVITATION_URL . '/otro-evento/AB1234')->assertNotFound();
        $this->getJson(INVITATION_URL . '/no-existe/AB1234')->assertNotFound();

        $event->update(['status' => 'draft']);
        $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->assertNotFound();
    });
});

describe('POST invitation rsvp', function () {
    it('saves the answer for the guest and their companions and logs it', function () {
        [$event, $main, $companions] = invitationFixture();

        $this->postJson(INVITATION_URL . '/xv-de-valentina/AB1234/rsvp', [
            'guest' => ['rsvpStatus' => 'attending'],
            'companions' => [
                ['id' => $companions[0]->id, 'rsvpStatus' => 'attending'],
                ['id' => $companions[1]->id, 'rsvpStatus' => 'not-attending'],
            ],
        ])->assertOk()->assertJsonPath('data.guest.status', 'attending');

        expect($main->fresh()->rsvp_status)->toBe('attending')
            ->and($main->fresh()->rsvp_status_date)->not->toBeNull()
            ->and($companions[0]->fresh()->rsvp_status)->toBe('attending')
            ->and($companions[1]->fresh()->rsvp_status)->toBe('not-attending')
            ->and(GuestRsvpLog::count())->toBe(3);
    });

    it('declines everyone when the main guest declines', function () {
        [$event, $main, $companions] = invitationFixture();

        $this->postJson(INVITATION_URL . '/xv-de-valentina/AB1234/rsvp', [
            'guest' => ['rsvpStatus' => 'not-attending'],
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending']],
        ])->assertOk();

        expect($companions->fresh()->pluck('rsvp_status')->unique()->all())->toBe(['not-attending']);
    });

    it('rejects ids that are not the guest’s companions', function () {
        [$event, $main] = invitationFixture();
        $stranger = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'pending']);

        $this->postJson(INVITATION_URL . '/xv-de-valentina/AB1234/rsvp', [
            'guest' => ['rsvpStatus' => 'attending'],
            'companions' => [['id' => $stranger->id, 'rsvpStatus' => 'attending']],
        ])->assertUnprocessable()->assertJsonValidationErrors('companions');

        expect($stranger->fresh()->rsvp_status)->toBe('pending')
            ->and($main->fresh()->rsvp_status)->toBe('pending');
    });

    it('validates the statuses', function () {
        invitationFixture();

        $this->postJson(INVITATION_URL . '/xv-de-valentina/AB1234/rsvp', ['guest' => ['rsvpStatus' => 'pending']])
            ->assertUnprocessable()->assertJsonValidationErrors('guest.rsvpStatus');
    });

    it('refuses answers after the deadline', function () {
        [$event, $main] = invitationFixture();
        DB::table('rsvps')->insert([
            'event_id' => $event->id, 'title' => 'RSVP', 'description' => '', 'custom_fields' => json_encode([]),
            'confirmation_deadline' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson(INVITATION_URL . '/xv-de-valentina/AB1234/rsvp', ['guest' => ['rsvpStatus' => 'attending']])
            ->assertUnprocessable()->assertJsonValidationErrors('rsvp');

        $this->getJson(INVITATION_URL . '/xv-de-valentina/AB1234')->assertJsonPath('data.sections.1.data.isOpen', false);
        expect($main->fresh()->rsvp_status)->toBe('pending');
    });

    it('cannot answer with a wrong code', function () {
        invitationFixture();

        $this->postJson(INVITATION_URL . '/xv-de-valentina/NOPE00/rsvp', ['guest' => ['rsvpStatus' => 'attending']])
            ->assertNotFound();
    });
});

describe('schema', function () {
    it('keeps event slugs unique', function () {
        Events::factory()->create(['custom_url_slug' => 'repetido']);

        expect(fn () => Events::factory()->create(['custom_url_slug' => 'repetido']))
            ->toThrow(QueryException::class);
    });
});
