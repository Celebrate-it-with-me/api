<?php

use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const GATE_URL = '/api/v1/app/invitation';

beforeEach(fn () => $this->seed(RolesAndPermissionSeeder::class));

/** Forget one limiter key of the POST (the throttle middleware stores it hashed with the limiter name). */
function gateForget(string $key): void
{
    RateLimiter::clear(md5('invitation-access-verify' . $key));
}

/** A published event with an owner called $ownerName and a main guest with the given code. */
function gateEvent(string $slug = 'xv-de-valentina', string $status = 'published', ?string $ownerName = 'Marisol Pérez', string $code = 'AB1234'): array
{
    $event = Events::factory()->create(['custom_url_slug' => $slug, 'status' => $status]);
    if ($ownerName !== null) {
        $owner = User::factory()->create(['name' => $ownerName]);
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    }
    $guest = Guest::factory()->create(['event_id' => $event->id, 'code' => $code, 'parent_id' => null]);

    return [$event, $guest];
}

describe('GET invitation/{slug}/access', function () {
    it('gives the first name of the organizer of a published event and nothing else', function () {
        gateEvent();

        $response = $this->getJson(GATE_URL . '/xv-de-valentina/access')->assertOk();

        expect($response->json())->toBe(['data' => ['organizer_name' => 'Marisol']])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        expect($response->getContent())->not->toContain('Pérez')->not->toContain('@');
    });

    it('answers the same shape and status for an unknown slug, a draft and an owner without a name', function () {
        gateEvent('borrador', 'draft');
        gateEvent('sin-nombre', 'published', '   ');
        gateEvent('sin-dueno', 'published', null);

        foreach (['no-existe', 'borrador', 'sin-nombre', 'sin-dueno'] as $slug) {
            $this->getJson(GATE_URL . "/{$slug}/access")->assertOk()->assertExactJson(['data' => ['organizer_name' => null]]);
        }
    });

    it('treats an account-archived event exactly as a missing one, unlike the published one', function () {
        [$archived] = gateEvent('archivado', 'archived');
        $archived->forceFill(['purge_after' => now()->addDays(10)])->save();
        gateEvent('publicado');

        $this->getJson(GATE_URL . '/archivado/access')->assertOk()->assertExactJson(['data' => ['organizer_name' => null]]);
        $this->getJson(GATE_URL . '/publicado/access')->assertOk()->assertExactJson(['data' => ['organizer_name' => 'Marisol']]);
    });

    it('is not shadowed by the invitation route: "access" is not read as a guest code', function () {
        gateEvent();

        $this->getJson(GATE_URL . '/xv-de-valentina/access')->assertOk()->assertJsonMissingPath('data.guest');
    });

    it('collapses spaces inside the name and uses the first word', function () {
        gateEvent('uno', 'published', '  Ana   María  López ');

        $this->getJson(GATE_URL . '/uno/access')->assertExactJson(['data' => ['organizer_name' => 'Ana']]);
    });
});

describe('POST invitation/{slug}/access/verify', function () {
    it('rejects the code of an account-archived event with the same 422 as a missing event, and accepts the published one', function () {
        [$archived] = gateEvent('archivado', 'archived', 'Ana', 'AR0001');
        $archived->forceFill(['purge_after' => now()->addDays(10)])->save();
        gateEvent('publicado', 'published', 'Ana', 'PU0001');

        $missing = $this->postJson(GATE_URL . '/no-existe/access/verify', ['code' => 'AR0001'])->assertStatus(422);
        gateForget('invitation-access-ip-min|127.0.0.1');
        $response = $this->postJson(GATE_URL . '/archivado/access/verify', ['code' => 'AR0001'])->assertStatus(422);
        gateForget('invitation-access-ip-min|127.0.0.1');

        expect($response->getContent())->toBe($missing->getContent())
            ->and($response->json('code'))->toBe('invalid_code');
        $this->postJson(GATE_URL . '/publicado/access/verify', ['code' => 'PU0001'])->assertOk()->assertJsonPath('data.valid', true);
        // The invitation itself (findEvent) still serves the archived event read-only.
        expect(app(InvitationService::class)->findEvent('archivado')?->id)->toBe($archived->id);
    });

    it('accepts the code of a guest of that event and returns the canonical code', function () {
        gateEvent();

        $response = $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'AB1234'])->assertOk();

        expect($response->json())->toBe(['data' => ['valid' => true, 'code' => 'AB1234']]);
    });

    it('normalizes what a person types: whitespace, dashes and lowercase', function () {
        gateEvent();

        foreach ([' ab1234 ', 'ab 12 34', 'AB-1234', "ab\t1234"] as $typed) {
            $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => $typed])
                ->assertOk()->assertExactJson(['data' => ['valid' => true, 'code' => 'AB1234']]);
            gateForget('invitation-access-ip-min|127.0.0.1');
        }
    });

    it('answers the identical 422 for a wrong code, an unknown slug, a draft event and a code of another event', function () {
        [, $guest] = gateEvent();
        gateEvent('borrador', 'draft', 'Lucia', 'DR0001');
        gateEvent('otro', 'published', 'Carlos', 'ZZ9999');

        $bodies = [];
        foreach ([
            ['xv-de-valentina', 'NOPE00'],
            ['no-existe', $guest->code],
            ['borrador', 'DR0001'],
            ['xv-de-valentina', 'ZZ9999'],   // valid in another event
        ] as $i => [$slug, $code]) {
            $response = $this->postJson(GATE_URL . "/{$slug}/access/verify", ['code' => $code])->assertStatus(422);
            $bodies[] = $response->getContent();
            gateForget('invitation-access-ip-min|127.0.0.1');
            gateForget('invitation-access-slug|' . $slug);
        }

        expect(array_unique($bodies))->toHaveCount(1)
            ->and(json_decode($bodies[0], true)['code'])->toBe('invalid_code');
    });

    it('answers that same 422 when the code is missing, empty, too long or not a string', function () {
        gateEvent();
        $expected = $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'NOPE00'])->getContent();

        foreach ([[], ['code' => ''], ['code' => str_repeat('A', 41)], ['code' => ['AB1234']]] as $payload) {
            gateForget('invitation-access-ip-min|127.0.0.1');
            $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', $payload)->assertStatus(422);
            expect($this->postJson(GATE_URL . '/xv-de-valentina/access/verify', $payload)->getContent())->toBe($expected);
            gateForget('invitation-access-ip-min|127.0.0.1');
        }
    });

    it('does not open with the code of a companion (only main guests have invitations)', function () {
        [$event, $main] = gateEvent();
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'CC0001', 'parent_id' => $main->id]);

        $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'CC0001'])->assertStatus(422);
    });

    it('whitespace-only code is invalid, never a match on an empty code', function () {
        gateEvent();
        Guest::factory()->create(['event_id' => Events::first()->id, 'code' => null, 'parent_id' => null]);

        $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => '   '])->assertStatus(422);
    });

    it('returns no guest or event data', function () {
        [, $guest] = gateEvent();

        $content = $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'AB1234'])->getContent();

        expect($content)->not->toContain($guest->name)->not->toContain('xv-de-valentina');
    });

    it('logs a wrong attempt without the full code', function () {
        gateEvent();
        Log::spy();

        $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'qq 98 76'])->assertStatus(422);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'invitation access: wrong code'
                && $context['slug'] === 'xv-de-valentina'
                && $context['code_hint'] === 'QQ***'
                && ! str_contains(json_encode($context), 'QQ9876')
                && ! str_contains(json_encode($context), '9876');
        })->once();
    });

    it('does not log a successful attempt as wrong', function () {
        gateEvent();
        Log::spy();

        $this->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'AB1234'])->assertOk();

        Log::shouldNotHaveReceived('info');
    });
});

describe('limits on POST access/verify', function () {
    it('limits per IP to 5 a minute with a 429 the app can read', function () {
        gateEvent();

        foreach (range(1, 5) as $i) {
            $this->postJson(GATE_URL . "/slug-$i/access/verify", ['code' => 'NOPE00'])->assertStatus(422);
        }

        $this->postJson(GATE_URL . '/slug-6/access/verify', ['code' => 'NOPE00'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertJsonStructure(['message']);
    });

    it('limits per IP to 30 an hour even when the minute window resets', function () {
        gateEvent();

        foreach (range(1, 30) as $i) {
            gateForget('invitation-access-ip-min|127.0.0.1');
            gateForget('invitation-access-slug|slug-' . $i);
            $this->postJson(GATE_URL . "/slug-$i/access/verify", ['code' => 'NOPE00'])->assertStatus(422);
        }
        gateForget('invitation-access-ip-min|127.0.0.1');

        $this->postJson(GATE_URL . '/slug-31/access/verify', ['code' => 'NOPE00'])->assertStatus(429);
    });

    it('limits per slug to 10 a minute across different IPs', function () {
        gateEvent();

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'NOPE00'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson(GATE_URL . '/xv-de-valentina/access/verify', ['code' => 'NOPE00'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        // Another slug is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson(GATE_URL . '/otro/access/verify', ['code' => 'NOPE00'])->assertStatus(422);
    });

    it('limits the slug case-insensitively', function () {
        foreach (range(1, 10) as $i) {
            $slug = $i % 2 ? 'Xv-Test' : 'xv-test';
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])->postJson(GATE_URL . "/$slug/access/verify", ['code' => 'X'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])->postJson(GATE_URL . '/XV-TEST/access/verify', ['code' => 'X'])->assertStatus(429);
    });

    it('limits GET access at 60 a minute', function () {
        foreach (range(1, 60) as $i) {
            $this->getJson(GATE_URL . '/nada/access')->assertOk();
        }

        $this->getJson(GATE_URL . '/nada/access')->assertStatus(429);
    });
});
