<?php

use App\Models\EventFeature;
use App\Models\EventLocation;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['app.frontend_app.url' => 'https://app.quinvite.test']);
});

function shareEvent(array $extra = [], array $guest = []): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'status' => 'published',
        'custom_url_slug' => 'xv-de-valentina',
        'event_name' => 'Los XV de Valentina',
        'start_date' => '2026-12-25 19:00:00',
        'locale' => 'es',
    ], $extra));
    EventFeature::create(['event_id' => $event->id]);
    $guest = Guest::factory()->create(array_merge(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null], $guest));

    return [$event, $guest];
}

it('gives a crawler the title, description, image and canonical address of the invitation', function () {
    [$event] = shareEvent();
    EventLocation::create(['event_id' => $event->id, 'type' => 'ceremony', 'name' => 'Salón', 'address' => '1 Main', 'city' => 'Miami', 'country' => 'US']);

    $response = $this->get('/og/xv-de-valentina/AB1234')->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('<meta property="og:title" content="Estás invitado/a a Los XV de Valentina">')
        ->toContain('Viernes 25 de diciembre de 2026 · Miami. Abre tu invitación.')
        ->toContain('<meta property="og:image" content="https://app.quinvite.test/og-default.png">')
        ->toContain('<meta property="og:url" content="https://app.quinvite.test/i/xv-de-valentina/AB1234">')
        ->toContain('<link rel="canonical" href="https://app.quinvite.test/i/xv-de-valentina/AB1234">')
        ->toContain('name="robots" content="noindex, nofollow"')
        ->toContain('http-equiv="refresh"');
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('speaks the language of the guest or of the link', function () {
    shareEvent(['locale' => 'es'], ['locale' => 'en']);

    $this->get('/og/xv-de-valentina/AB1234')->assertSee('You are invited to Los XV de Valentina', false)->assertSee('Friday, December 25, 2026', false);
    $this->get('/og/xv-de-valentina/AB1234?lang=es')->assertSee('Estás invitado/a a', false)
        ->assertSee('https://app.quinvite.test/i/xv-de-valentina/AB1234?lang=es', false);
});

it('says "save the date" while the event is in that stage', function () {
    [$event] = shareEvent();
    $event->forceFill(['invitation_stage' => 'save_the_date'])->save();

    $this->get('/og/xv-de-valentina/AB1234')->assertSee('Guarda la fecha: Los XV de Valentina', false);
});

it('uses the cover photo when the organizer set one', function () {
    shareEvent(['template_config' => ['sections' => [['type' => 'cover', 'enabled' => true, 'settings' => ['image' => 'https://cdn.example.com/portada.jpg']]]]]);

    $this->get('/og/xv-de-valentina/AB1234')->assertSee('<meta property="og:image" content="https://cdn.example.com/portada.jpg">', false);
});

it('escapes what the organizer typed', function () {
    shareEvent(['event_name' => 'Boda de "Ana" <script>alert(1)</script> & Luis']);

    $html = $this->get('/og/xv-de-valentina/AB1234')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});

it('answers the same 404 for a wrong slug, a wrong code, a companion code and an event that is not published', function () {
    [$event, $guest] = shareEvent();
    $companion = Guest::factory()->create(['event_id' => $event->id, 'code' => 'ZZ9999', 'parent_id' => $guest->id]);

    $this->get('/og/otro-evento/AB1234')->assertNotFound();
    $this->get('/og/xv-de-valentina/NOPE00')->assertNotFound();
    $this->get('/og/xv-de-valentina/ZZ9999')->assertNotFound();

    $event->update(['status' => 'draft']);
    $this->get('/og/xv-de-valentina/AB1234')->assertNotFound();
});

it('does not put private data in the preview', function () {
    shareEvent([], ['name' => 'Rosa Secreta', 'phone' => '+13055550199', 'email' => 'rosa@secreto.test']);

    $html = $this->get('/og/xv-de-valentina/AB1234')->getContent();

    expect($html)->not->toContain('Rosa Secreta')->not->toContain('+13055550199')->not->toContain('rosa@secreto.test');
});
