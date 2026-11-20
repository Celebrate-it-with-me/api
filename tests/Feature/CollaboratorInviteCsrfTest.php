<?php

use App\Models\Events;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    // CSRF is skipped under the "testing" environment: force a real one so the middleware runs.
    app()->detectEnvironment(fn () => 'local');
});

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

const CI_HEADERS = ['Origin' => 'http://dev.app.cwm.com', 'Referer' => 'http://dev.app.cwm.com/'];

it('lets the public invite decline through from the SPA domain without a CSRF token', function () {
    $event = Events::factory()->create();

    // A bogus token reaches the controller (422 invitation_invalid) instead of being stopped with 419.
    $this->withHeaders(CI_HEADERS)->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/not-a-token/decline")
        ->assertStatus(422);
});

it('still requires CSRF on the authenticated accept route', function () {
    $this->withHeaders(CI_HEADERS)->postJson('/api/v1/app/event/1/collaborators/invite/1/accept')
        ->assertStatus(419);
});
