<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

// B1: an event id that is not a number must be a 404 (route not found), never a 500 from the bigint cast.
it('answers 404 to a non-numeric event id on the organizer routes', function (string $path) {
    $this->actingAs(User::factory()->create())->getJson("/api/v1/app/event/abc/$path")->assertNotFound();
})->with(['rsvp/stats', 'budget', 'timeline', 'dress-code', 'dashboard-logs', 'onboarding', 'invitation', 'assistant/next', 'whatsapp/audience', 'whatsapp/summary']);

it('answers 404 to a non-numeric event id on the guest and collaborator routes', function () {
    $this->getJson('/api/v1/app/template/event/abc/comments')->assertNotFound();
    $this->getJson('/api/v1/app/event/abc/collaborators/invite/some-token')->assertNotFound();
    $this->postJson('/api/v1/app/event/abc/collaborators/invite/some-token/decline')->assertNotFound();
});

it('declares the numeric constraint on every route with an {event} parameter', function () {
    $loose = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), '{event}') && ! isset($route->wheres['event']))
        ->map(fn ($route) => $route->uri())->values()->all();

    expect($loose)->toBe([]);
});
