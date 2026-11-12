<?php

use App\Models\EventFeature;
use App\Models\EventLocation;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\SaveTheDate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function stdEvent(string $status = 'draft', string $plan = 'basic'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => $status,
        'custom_url_slug' => 'xv-std-' . uniqid(),
        'event_name' => 'Los XV de Valentina',
        'start_date' => '2027-03-20 16:00:00',
        'end_date' => '2027-03-20 23:00:00',
        'timezone' => 'America/New_York',
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}"];
}

function std(array $extra = []): array
{
    return array_merge(['message' => 'Guarda la fecha: ¡nos casamos pronto!', 'showCountdown' => true, 'useAddToCalendar' => true], $extra);
}

describe('settings', function () {
    it('answers the defaults when nothing was set and saves message and switches', function () {
        [, $u, $api] = stdEvent();

        $this->actingAs($u['owner'])->getJson("$api/save-the-date")->assertOk()
            ->assertJsonPath('data', ['message' => null, 'showCountdown' => true, 'useAddToCalendar' => true]);

        $this->actingAs($u['owner'])->putJson("$api/save-the-date", std(['showCountdown' => false]))->assertOk()
            ->assertJsonPath('data.message', 'Guarda la fecha: ¡nos casamos pronto!')->assertJsonPath('data.showCountdown', false);
        $this->actingAs($u['owner'])->putJson("$api/save-the-date", std(['message' => '']))->assertOk()->assertJsonPath('data.message', null);
        expect(SaveTheDate::count())->toBe(1);
    });

    it('validates and keeps viewers and strangers out', function () {
        [, $u, $api] = stdEvent();

        $this->actingAs($u['owner'])->putJson("$api/save-the-date", std(['message' => str_repeat('a', 501)]))->assertJsonValidationErrors('message');
        $this->actingAs($u['owner'])->putJson("$api/save-the-date", ['message' => 'x'])->assertJsonValidationErrors(['showCountdown', 'useAddToCalendar']);
        $this->actingAs($u['viewer'])->getJson("$api/save-the-date")->assertOk();
        $this->actingAs($u['viewer'])->putJson("$api/save-the-date", std())->assertForbidden();
        $this->actingAs($u['stranger'])->getJson("$api/save-the-date")->assertNotFound();
    });
});

describe('stages', function () {
    it('publishes the Save the date, then upgrades the same event to the invitation', function () {
        [$event, $u, $api] = stdEvent();

        $this->actingAs($u['owner'])->postJson("$api/publish", ['stage' => 'save_the_date'])->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.invitationStage', 'save_the_date');
        $this->actingAs($u['owner'])->postJson("$api/publish", ['stage' => 'save_the_date'])->assertStatus(422);
        $this->actingAs($u['owner'])->postJson("$api/publish")->assertOk()->assertJsonPath('data.invitationStage', 'invitation');
        $this->actingAs($u['owner'])->postJson("$api/publish", ['stage' => 'save_the_date'])->assertStatus(422);
        expect($event->fresh()->published_at)->not->toBeNull();
    });

    it('rejects an unknown stage', function () {
        [, $u, $api] = stdEvent();

        $this->actingAs($u['owner'])->postJson("$api/publish", ['stage' => 'later'])->assertJsonValidationErrors('stage');
    });

    it('applies the guest limit to the Save the date too', function () {
        [$event, $u, $api] = stdEvent();
        Guest::factory()->count(31)->create(['event_id' => $event->id, 'parent_id' => null]);

        $this->actingAs($u['owner'])->postJson("$api/publish", ['stage' => 'save_the_date'])->assertStatus(402)->assertJsonPath('code', 'guest_limit_exceeded');
    });
});

describe('public link', function () {
    function stdLink(string $stage): array
    {
        [$event, $u, $api] = stdEvent('published');
        $event->forceFill(['invitation_stage' => $stage])->save();
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null]);
        test()->seed(InvitationTemplateSeeder::class);

        return [$event, "/api/v1/app/invitation/{$event->custom_url_slug}/AB1234"];
    }

    it('shows only what is decided during the Save the date stage', function () {
        [$event, $url] = stdLink('save_the_date');
        EventLocation::create(['event_id' => $event->id, 'type' => 'ceremony', 'name' => 'Parroquia', 'address' => '1 Main', 'city' => 'Miami', 'country' => 'US']);
        SaveTheDate::create(['event_id' => $event->id, 'message' => 'Anótalo en tu calendario', 'show_countdown' => true, 'use_add_to_calendar' => true]);

        $data = $this->getJson($url)->assertOk()->json('data');
        $sections = collect($data['sections']);

        expect($data['stage'])->toBe('save_the_date')
            ->and($sections->pluck('type')->all())->toBe(['cover', 'countdown', 'save_the_date', 'closing'])
            ->and($sections->firstWhere('type', 'countdown')['data']['startsAt'])->toBe('2027-03-20T16:00:00')
            ->and($sections->firstWhere('type', 'save_the_date')['data']['message'])->toBe('Anótalo en tu calendario')
            ->and($sections->firstWhere('type', 'save_the_date')['data']['calendar']['google'])->toStartWith('https://calendar.google.com/calendar/render?');
        expect(json_encode($data))->not->toContain('Parroquia');
    });

    it('respects the two switches', function () {
        [$event, $url] = stdLink('save_the_date');
        SaveTheDate::create(['event_id' => $event->id, 'message' => null, 'show_countdown' => false, 'use_add_to_calendar' => false]);

        expect(collect($this->getJson($url)->json('data.sections'))->pluck('type')->all())->toBe(['cover', 'closing']);
    });

    it('shows the full invitation once the event is upgraded, with the same link', function () {
        [$event, $url] = stdLink('invitation');

        $data = $this->getJson($url)->json('data');

        expect($data['stage'])->toBe('invitation')
            ->and(collect($data['sections'])->pluck('type')->all())->toContain('rsvp');
    });

    it('does not take answers during the Save the date stage', function () {
        [, $url] = stdLink('save_the_date');

        $this->postJson("$url/rsvp", ['guest' => ['rsvpStatus' => 'attending']])->assertStatus(422);
    });

    it('serves the event as a calendar file', function () {
        [$event, $url] = stdLink('save_the_date');

        $response = $this->get("$url/calendar.ics")->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('text/calendar')
            ->and($response->getContent())->toContain('BEGIN:VEVENT', 'DTSTART;TZID=America/New_York:20270320T160000', 'SUMMARY:Los XV de Valentina');
        $this->get("/api/v1/app/invitation/{$event->custom_url_slug}/ZZZZZZ/calendar.ics")->assertNotFound();
    });
});
