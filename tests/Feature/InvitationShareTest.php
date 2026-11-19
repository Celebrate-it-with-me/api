<?php

use App\Http\Services\AppServices\Invitation\CoverPhoto;
use App\Http\Services\AppServices\InvitationService;
use App\Http\Services\AppServices\InvitationShare;
use App\Models\EventLocation;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['app.frontend_app.url' => 'https://app.quinvite.test']);
    Storage::fake('event-media');
});

function shareJpeg(int $w = 1800, int $h = 2400): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 4) {
        imagefilledrectangle($im, 0, $y, $w, $y + 3, imagecolorallocate($im, 40 + (int) ($y * 150 / $h), 90, 160));
    }
    ob_start();
    imagejpeg($im, null, 90);

    return (string) ob_get_clean();
}

/** Stores a real cover photo for the event and points its config at it; returns the stored path. */
function shareCover(Events $event, ?string $focus = null, int $w = 1800, int $h = 2400): string
{
    $stored = app(CoverPhoto::class)->store($event->id, UploadedFile::fake()->createWithContent('f.jpg', shareJpeg($w, $h)), 0, $focus);
    $event->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => array_filter(['image' => CoverPhoto::PREFIX . $stored['path'], 'focus' => $focus])]]]]);

    return $stored['path'];
}

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
    $guest = Guest::factory()->create(array_merge(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null], $guest));

    return [$event, $guest];
}

it('gives a crawler the title, description, image and canonical address of the invitation', function () {
    [$event] = shareEvent([], ['name' => 'Rosa Pérez']);
    EventLocation::create(['event_id' => $event->id, 'type' => 'ceremony', 'name' => 'Salón', 'address' => '1 Main', 'city' => 'Miami', 'country' => 'US']);

    $response = $this->get('/og/xv-de-valentina/AB1234')->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('<meta property="og:title" content="Rosa Pérez, estás invitado/a a Los XV de Valentina">')
        ->toContain('Viernes 25 de diciembre de 2026 · Miami. Abre tu invitación.')
        ->toContain('<meta property="og:image" content="https://app.quinvite.test/og-default.png">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta property="og:image:alt" content="Quinvite">')
        ->toContain('<meta property="og:url" content="https://app.quinvite.test/i/xv-de-valentina/AB1234">')
        ->toContain('<link rel="canonical" href="https://app.quinvite.test/i/xv-de-valentina/AB1234">')
        ->toContain('name="robots" content="noindex, nofollow"')
        ->toContain('http-equiv="refresh"');
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('speaks the language of the guest or of the link', function () {
    shareEvent(['locale' => 'es'], ['locale' => 'en', 'name' => 'Rosa']);

    $this->get('/og/xv-de-valentina/AB1234')->assertSee('Rosa, you are invited to Los XV de Valentina', false)->assertSee('Friday, December 25, 2026', false);
    $this->get('/og/xv-de-valentina/AB1234?lang=es')->assertSee('Rosa, estás invitado/a a', false)
        ->assertSee('https://app.quinvite.test/i/xv-de-valentina/AB1234?lang=es', false);
});

it('says "save the date" while the event is in that stage', function () {
    [$event, $guest] = shareEvent([], ['name' => 'Rosa']);
    $event->forceFill(['invitation_stage' => 'save_the_date'])->save();

    $this->get('/og/xv-de-valentina/AB1234')->assertSee('Rosa, guarda la fecha: Los XV de Valentina', false);
    $this->get('/og/xv-de-valentina/AB1234?lang=en')->assertSee('Rosa, save the date: Los XV de Valentina', false);

    $guest->update(['name' => '   ']);
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

it('puts the name of the guest in the title but no other private data', function () {
    shareEvent([], ['name' => 'Rosa Secreta', 'phone' => '+13055550199', 'email' => 'rosa@secreto.test']);

    $html = $this->get('/og/xv-de-valentina/AB1234')->getContent();

    expect($html)->toContain('Rosa Secreta, estás invitado/a')->not->toContain('+13055550199')->not->toContain('rosa@secreto.test');
});

it('cleans, escapes and cuts the name of the guest', function () {
    shareEvent([], ['name' => "  <b>Ana</b>\n\t\"María\"  " . str_repeat('x', 80)]);

    $html = $this->get('/og/xv-de-valentina/AB1234')->getContent();

    expect($html)->not->toContain('<b>Ana</b>')->toContain('&lt;b&gt;Ana&lt;/b&gt; &quot;María&quot;')->toContain('…, estás invitado/a')
        ->not->toContain(str_repeat('x', 50));
});

it('never shows the name when the code is wrong: the 404 is the same whatever the guest is called', function () {
    shareEvent([], ['name' => 'Rosa Secreta']);

    $wrong = $this->get('/og/xv-de-valentina/NOPE00');
    $other = $this->get('/og/otro-evento/AB1234');

    $wrong->assertNotFound();
    expect($wrong->getContent())->not->toContain('Rosa Secreta')->and($other->getContent())->toBe($wrong->getContent());
    expect($wrong->headers->get('Cache-Control'))->not->toContain('max-age=300');
});

it('serves the uploaded cover as a 1200x630 JPEG with its real measures and alt', function () {
    [$event] = shareEvent();
    $path = shareCover($event, '30% 20%');

    $html = $this->get('/og/xv-de-valentina/AB1234')->assertOk()->getContent();

    $disk = Storage::disk('event-media');
    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeTrue();
    $bytes = $disk->get(CoverPhoto::sharePath($path));
    $info = getimagesizefromstring($bytes);
    expect($info[0])->toBe(1200)->and($info[1])->toBe(630)->and($info['mime'])->toBe('image/jpeg')->and(strlen($bytes))->toBeLessThan(300_000)
        ->and(str_contains($bytes, 'Exif'))->toBeFalse();
    expect($html)->toContain('og:image" content="' . $disk->url(CoverPhoto::sharePath($path)) . '?v=')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta property="og:image:alt" content="Los XV de Valentina">')
        ->not->toContain('og-default.png');
});

it('keeps the image under 300 KB even for a noisy photo', function () {
    [$event] = shareEvent();
    $im = imagecreatetruecolor(2400, 1600);
    mt_srand(7);
    for ($i = 0; $i < 60000; $i++) {
        imagesetpixel($im, mt_rand(0, 2399), mt_rand(0, 1599), mt_rand(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($im, null, 95);
    $noise = (string) ob_get_clean();
    $stored = app(CoverPhoto::class)->store($event->id, UploadedFile::fake()->createWithContent('f.jpg', $noise));

    expect(strlen(Storage::disk('event-media')->get(CoverPhoto::sharePath($stored['path']))))->toBeLessThan(300_000);
});

it('makes the share image on the first request when the photo was uploaded before it existed, and keeps it after', function () {
    [$event] = shareEvent();
    $path = shareCover($event);
    $disk = Storage::disk('event-media');
    $disk->delete(CoverPhoto::sharePath($path));

    $this->get('/og/xv-de-valentina/AB1234')->assertOk()->assertSee(CoverPhoto::sharePath($path), false);
    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeTrue();
    $first = $disk->get(CoverPhoto::sharePath($path));
    $this->get('/og/xv-de-valentina/AB1234')->assertOk();
    expect($disk->get(CoverPhoto::sharePath($path)))->toBe($first);
});

it('falls back to the brand image when the stored photo has vanished', function () {
    [$event] = shareEvent();
    $path = shareCover($event);
    Storage::disk('event-media')->delete([$path, CoverPhoto::sharePath($path)]);

    $this->get('/og/xv-de-valentina/AB1234')->assertOk()->assertSee('og:image" content="https://app.quinvite.test/og-default.png"', false);
});

it('never serves an upload that belongs to another event', function () {
    [$event] = shareEvent();
    $other = Events::factory()->create();
    $foreign = shareCover($other);
    $event->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => CoverPhoto::PREFIX . $foreign]]]]]);
    // And a path that was never one of ours.
    $html = $this->get('/og/xv-de-valentina/AB1234')->assertOk()->getContent();
    expect($html)->toContain('og-default.png')->not->toContain($foreign);

    $event->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => 'upload:../../etc/passwd']]]]]);
    $this->get('/og/xv-de-valentina/AB1234')->assertOk()->assertSee('og-default.png', false);
});

it('uses the cover of the template when the organizer has none, a path of the app, and the brand image for anything odd', function () {
    [$event] = shareEvent();
    $set = fn (string $image) => $event->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => $image]]]]]);

    $set('/demo/valentina-portrait.webp');
    $this->get('/og/xv-de-valentina/AB1234')->assertSee('og:image" content="https://app.quinvite.test/demo/valentina-portrait.webp"', false)
        ->assertDontSee('og:image:width', false)->assertSee('og:image:alt', false);

    $set('//evil.test/x.png');
    $this->get('/og/xv-de-valentina/AB1234')->assertSee('og-default.png', false);

    $set('javascript:alert(1)');
    $this->get('/og/xv-de-valentina/AB1234')->assertSee('og-default.png', false);

    $event->update(['template_config' => null]);
    $version = app(InvitationService::class)->templateFor($event)->current_version;
    $version?->update(['sections' => [['type' => 'cover', 'settings' => ['image' => 'https://cdn.example.com/plantilla.jpg']]]]);
    Cache::flush();
    if ($version) {
        $this->get('/og/xv-de-valentina/AB1234')->assertSee('og:image" content="https://cdn.example.com/plantilla.jpg"', false);
    }
});

it('is private to the browser, never cached by a shared cache', function () {
    shareEvent();

    $cache = $this->get('/og/xv-de-valentina/AB1234')->assertOk()->headers->get('Cache-Control');

    expect($cache)->toContain('private')->toContain('max-age=300')->not->toContain('public');
});

it('does not mark the invitation as opened nor write activity: a crawler is not the guest', function () {
    [$event, $guest] = shareEvent();
    $before = DB::table('event_activities')->count();

    $this->get('/og/xv-de-valentina/AB1234', ['User-Agent' => 'WhatsApp/2.23.20.0'])->assertOk();
    $this->get('/og/xv-de-valentina/AB1234', ['User-Agent' => 'facebookexternalhit/1.1'])->assertOk();

    expect($guest->fresh()->invitation_opened_at)->toBeNull()
        ->and(DB::table('event_activities')->count())->toBe($before);
});

it('allows a burst of crawlers per IP', function () {
    expect(RouteServiceProvider::ROUTE_LIMITS['og-share'])->toBe([300, 1]);
    shareEvent();
    foreach (range(1, 70) as $_) {
        $this->get('/og/xv-de-valentina/AB1234')->assertOk();
    }
});

it('computes the card of the wizard with the same title, description, image and alt', function () {
    [$event, $guest] = shareEvent([], ['name' => 'Rosa']);
    $path = shareCover($event);

    $card = app(InvitationShare::class)->linkPreview($guest);
    $meta = app(InvitationShare::class)->metadata('xv-de-valentina', 'AB1234', null);

    expect($card)->toMatchArray(['title' => $meta['title'], 'description' => $meta['description'], 'image' => $meta['image'], 'alt' => 'Los XV de Valentina', 'imageWidth' => 1200, 'imageHeight' => 630])
        ->and($card['title'])->toBe('Rosa, estás invitado/a a Los XV de Valentina');
    expect(app(InvitationShare::class)->linkPreview($guest, 'en')['title'])->toBe('Rosa, you are invited to Los XV de Valentina');

    // A draft still gets its card (the wizard warns by itself); the crawler page does not.
    $event->update(['status' => 'draft']);
    expect(app(InvitationShare::class)->linkPreview($guest->fresh())['title'])->toContain('Rosa');
    $this->get('/og/xv-de-valentina/AB1234')->assertNotFound();
});

it('removes the share image together with the photo', function () {
    [$event] = shareEvent();
    $path = shareCover($event);
    $disk = Storage::disk('event-media');
    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeTrue();

    app(CoverPhoto::class)->delete($path);

    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeFalse()->and($disk->exists($path))->toBeFalse();
    expect(app(CoverPhoto::class)->makeShare('images/not-a-cover.webp'))->toBeFalse();

    $empty = "images/event/{$event->id}/cover/" . strtolower((string) \Illuminate\Support\Str::ulid()) . '-100x100.webp';
    $disk->put($empty, '');
    expect(app(CoverPhoto::class)->makeShare($empty))->toBeFalse();
});

it('crops around the focus point', function () {
    [$event] = shareEvent();
    // Tall photo: the top is red, the bottom blue.
    $im = imagecreatetruecolor(1200, 2400);
    imagefilledrectangle($im, 0, 0, 1200, 1199, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, 0, 1200, 1200, 2400, imagecolorallocate($im, 0, 0, 255));
    ob_start();
    imagejpeg($im, null, 95);
    $stored = app(CoverPhoto::class)->store($event->id, UploadedFile::fake()->createWithContent('f.jpg', (string) ob_get_clean()), 0, '50% 0%');
    $bytes = Storage::disk('event-media')->get(CoverPhoto::sharePath($stored['path']));
    $px = imagecreatefromstring($bytes);
    expect((imagecolorat($px, 600, 315) >> 16) & 255)->toBeGreaterThan(200);

    app(CoverPhoto::class)->makeShare($stored['path'], '50% 100%', true);
    $px = imagecreatefromstring(Storage::disk('event-media')->get(CoverPhoto::sharePath($stored['path'])));
    expect(imagecolorat($px, 600, 315) & 255)->toBeGreaterThan(200);
});

it('builds the share images of the photos uploaded earlier: dry run, real run, idempotent, force', function () {
    [$event] = shareEvent();
    $path = shareCover($event);
    $other = Events::factory()->create(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => 'https://x.test/a.jpg']]]]]);
    $broken = Events::factory()->create();
    $broken->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => CoverPhoto::PREFIX . "images/event/{$broken->id}/cover/" . strtolower((string) Str::ulid()) . '-100x100.webp']]]]]);
    $disk = Storage::disk('event-media');
    $disk->delete(CoverPhoto::sharePath($path));

    $this->artisan('invitation:share-images', ['--dry-run' => true])->expectsOutputToContain("event {$event->id}: would make")->assertSuccessful();
    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeFalse();

    $this->artisan('invitation:share-images')->expectsOutputToContain("event {$broken->id}: could not make")->assertFailed();
    expect($disk->exists(CoverPhoto::sharePath($path)))->toBeTrue();

    $broken->update(['template_config' => null]);
    $this->artisan('invitation:share-images')->expectsOutputToContain('already there 1')->assertSuccessful();
    $first = $disk->get(CoverPhoto::sharePath($path));
    $this->artisan('invitation:share-images', ['--force' => true])->expectsOutputToContain('Made 1')->assertSuccessful();
    expect(getimagesizefromstring($disk->get(CoverPhoto::sharePath($path)))[0])->toBe(1200)->and($first)->not->toBe('');
});
