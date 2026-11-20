<?php

use App\Http\Services\Payments\FakeGateway;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function w27Owner(): array
{
    $user = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $user];
}

describe('payment webhook throttle (M-1)', function () {
    it('never throttles a signed request: garbage cannot starve the provider', function () {
        foreach (range(1, 130) as $_) {
            $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'bad'], '{}')
                ->assertStatus(400);
        }

        $raw = json_encode(['type' => 'unknown.event']);
        $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => FakeGateway::sign($raw)], $raw)
            ->assertOk();
    });

    it('still throttles requests with no signature header at all', function () {
        foreach (range(1, 120) as $_) {
            expect($this->postJson('/api/v1/app/payments/webhook', [])->status())->not->toBe(429);
        }
        $this->postJson('/api/v1/app/payments/webhook', [])->assertStatus(429);
    });
});

describe('coupon guessing (M-2)', function () {
    it('brakes invalid codes per user with a uniform 429, on quote and checkout alike', function () {
        [$event, $owner] = w27Owner();

        foreach (range(1, 6) as $i) {
            $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE$i")->assertStatus(422);
        }
        foreach (range(7, 10) as $i) {
            $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", ['code' => "NOPE$i"])->assertStatus(422);
        }

        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE11")
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", ['code' => 'NOPE12'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    });

    it('brakes per IP when the guesser rotates accounts', function () {
        foreach (range(1, 10) as $i) {
            [$event, $owner] = w27Owner();
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE$i")->assertStatus(422);
        }

        [$event, $fresh] = w27Owner();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->actingAs($fresh)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE")->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.10'])->actingAs($fresh)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE")->assertStatus(422);
    });

    it('does not count valid codes, no code, or other validation errors', function () {
        [$event, $owner] = w27Owner();
        Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900, 'is_founder' => true]);

        foreach (range(1, 15) as $_) {
            $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=FUND79")->assertOk();
            $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote")->assertOk();
        }
        foreach (range(1, 12) as $_) {
            $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=" . str_repeat('x', 70))->assertStatus(422);
        }
        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=FUND79")->assertOk();
    });
});

describe('send wizard preview throttle (B-5)', function () {
    it('has its own generous named limit', function () {
        expect(RouteServiceProvider::ROUTE_LIMITS['whatsapp-preview'])->toBe([120, 1]);

        $route = Route::getRoutes()->getByName('event.whatsapp.preview');
        expect($route->gatherMiddleware())->toContain('throttle:whatsapp-preview');
    });
});
