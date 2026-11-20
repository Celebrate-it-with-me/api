<?php

use App\Http\Services\WhatsApp\FakeGateway;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

describe('authorization', function () {
    it('answers 404 to strangers, 403 to a locked module and 403 to a viewer who sends', function () {
        [$event, $u, $api] = waEvent();
        $body = ['template' => 'invitation', 'audience' => 'all'];

        $this->getJson("$api/summary")->assertUnauthorized();
        $this->actingAs($u['stranger'])->postJson("$api/send", $body)->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$api/messages")->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$api/summary")->assertNotFound();
        $this->actingAs($u['viewer'])->postJson("$api/send", $body)->assertForbidden();
        $this->actingAs($u['viewer'])->getJson("$api/summary")->assertOk();

        [, $u2, $api2] = waEvent('basic');
        $this->actingAs($u2['owner'])->postJson("$api2/send", $body)->assertForbidden()->assertJsonPath('code', 'module_locked');

        [, $u3, $api3] = waEvent('premium', false);
        $this->actingAs($u3['owner'])->getJson("$api3/messages")->assertForbidden()->assertJsonPath('code', 'module_locked');
    });

    it('never shows or retries messages of another event', function () {
        [$event, $u, $api] = waEvent();
        [$other] = waEvent();
        $g = waGuest($other);
        $m = Message::create(['event_id' => $other->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'failed']);

        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertNotFound();
        $this->actingAs($u['owner'])->getJson("$api/messages")->assertOk()->assertJsonPath('meta.total', 0);
    });
});

describe('sending', function () {
    it('queues and sends to guests with a valid phone, with the template variables and button payloads', function () {
        [$event, $u, $api] = waEvent();
        $ana = waGuest($event, ['name' => 'Ana Pérez', 'phone' => '(305) 555-0123']);
        waGuest($event, ['name' => 'Peter', 'phone' => '+1 786 555 0100', 'locale' => 'en']);

        $res = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        expect($res->json('data.queued'))->toBe(2)->and($res->json('data.skipped'))->toBe([])->and($res->json('data.batchId'))->toBeString();

        expect(FakeGateway::$sent)->toHaveCount(2);
        $first = FakeGateway::$sent[0];
        expect($first['to'])->toBe('+13055550123')->and($first['template'])->toBe('quinvite_invitation_es')
            ->and($first['params'][0])->toBe('Ana Pérez')->and($first['params'][1])->toBe('Los XV de Valentina')
            ->and($first['params'][3])->toContain('/i/' . $event->custom_url_slug . '/' . $ana->code)
            ->and($first['payloads'])->toBe(["rsvp:{$ana->id}:yes", "rsvp:{$ana->id}:no"])
            ->and(FakeGateway::$sent[1]['template'])->toBe('quinvite_invitation_en');

        $m = Message::where('guest_id', $ana->id)->first();
        expect($m->status)->toBe('sent')->and($m->wa_message_id)->toStartWith('wamid.')->and($m->sent_at)->not->toBeNull();
        // The fake approved the draft on the spot.
        expect(Template::where('key', 'invitation')->where('locale', 'es')->value('status'))->toBe('approved');
    });

    it('skips with the contract reasons', function () {
        [$event, $u, $api] = waEvent();
        $ok = waGuest($event);
        waGuest($event, ['phone' => null]);
        waGuest($event, ['phone' => '123']);
        waGuest($event, ['whatsapp_opted_out_at' => now()]);
        waGuest($event, ['rsvp_status' => 'attending']);
        $body = ['template' => 'reminder_pending', 'audience' => 'all'];

        $res = $this->actingAs($u['owner'])->postJson("$api/send", $body)->assertStatus(202);
        $reasons = collect($res->json('data.skipped'))->pluck('reason')->sort()->values()->all();
        expect($res->json('data.queued'))->toBe(1)->and($reasons)->toBe(['already_responded', 'invalid_phone', 'no_phone', 'opted_out']);

        // Same template to the same guest within 24 h: skipped unless forced.
        $again = $this->actingAs($u['owner'])->postJson("$api/send", $body)->json('data');
        expect($again['queued'])->toBe(0)->and(collect($again['skipped'])->firstWhere('guestId', $ok->id)['reason'])->toBe('recently_sent');
        expect($this->actingAs($u['owner'])->postJson("$api/send", $body + ['force' => true])->json('data.queued'))->toBe(1);
    });

    it('audience pending leaves out answered guests; selected needs ids', function () {
        [$event, $u, $api] = waEvent();
        $a = waGuest($event);
        waGuest($event, ['rsvp_status' => 'attending']);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'pending'])->assertStatus(202)->assertJsonPath('data.queued', 1);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'selected'])
            ->assertStatus(422)->assertJsonPath('errors.guest_ids.0', 'required');
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'selected', 'guest_ids' => [$a->id]])
            ->assertStatus(202)->assertJsonPath('data.queued', 1);
    });

    it('does not send to guests of another event, and answers no_recipients', function () {
        [$event, $u, $api] = waEvent();
        [$other] = waEvent();
        $foreign = waGuest($other);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'selected', 'guest_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonPath('errors.audience.0', 'no_recipients');
        expect(Message::count())->toBe(0);
    });

    it('rejects more than 500 guests per request', function () {
        [$event, $u, $api] = waEvent();
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'selected', 'guest_ids' => range(1, 501)])
            ->assertStatus(422)->assertJsonPath('errors.guest_ids.0', 'too_many');

        Guest::factory()->count(501)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550123']);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])
            ->assertStatus(422)->assertJsonPath('errors.audience.0', 'too_many_recipients')->assertJsonMissingPath('errors.guest_ids');
        expect(Message::count())->toBe(0);
    });

    it('dry_run counts recipients and skips without queueing or sending', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        waGuest($event, ['phone' => null]);
        waGuest($event, ['whatsapp_opted_out_at' => now()]);

        $res = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'dry_run' => true])->assertOk();
        expect($res->json('data.queued'))->toBe(1)->and(collect($res->json('data.skipped'))->pluck('reason')->sort()->values()->all())->toBe(['no_phone', 'opted_out'])
            ->and($res->json('data'))->not->toHaveKey('batchId');
        expect(Message::count())->toBe(0)->and(FakeGateway::$sent)->toBe([]);

        $this->actingAs($u['viewer'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'dry_run' => true])->assertForbidden();
        $this->actingAs($u['stranger'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'dry_run' => true])->assertNotFound();
    });

    it('shows whatsappOptedOut in the roster', function () {
        [$event, $u] = waEvent();
        waGuest($event, ['name' => 'Baja', 'whatsapp_opted_out_at' => now()]);
        waGuest($event, ['name' => 'Alta']);
        $rows = collect($this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$event->id}/roster")->assertOk()->json('data'))->keyBy('name');
        expect($rows['Baja']['whatsappOptedOut'])->toBeTrue()->and($rows['Alta']['whatsappOptedOut'])->toBeFalse();
    });

    it('validates the template and audience', function () {
        [, $u, $api] = waEvent();
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'padrino_reminder', 'audience' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.template.0', 'invalid')->assertJsonPath('errors.audience.0', 'invalid');
    });

    it('answers 409 whatsapp_unavailable when the meta driver is not configured', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.access_token' => null]);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])
            ->assertStatus(409)->assertJsonPath('code', 'whatsapp_unavailable');
        expect(Message::count())->toBe(0);
    });

    it('answers 409 template_not_approved with the meta driver until the template is approved', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '111222', 'services.whatsapp.business_account_id' => '999888',
            'services.whatsapp.access_token' => 't', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REAL1']]], 200)]);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])
            ->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        Http::assertNothingSent();

        Template::where('key', 'invitation')->update(['status' => 'approved']);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        expect(Message::first()->wa_message_id)->toBe('wamid.REAL1');
    });

    it('marks a message failed with the error code when the gateway refuses, and retries it', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        FakeGateway::failNext(new WhatsAppException('131026', 'Message undeliverable', false));

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $m = Message::first();
        expect($m->status)->toBe('failed')->and($m->error_code)->toBe('131026')->and($m->failed_at)->not->toBeNull();

        $this->actingAs($u['owner'])->getJson("$api/messages?status=failed")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.errorCode', '131026');
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'sent');
        // Only failed messages can be retried.
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(409)->assertJsonPath('code', 'not_failed');
    });

    it('lists messages with the latest per guest, and summarizes by status', function () {
        [$event, $u, $api] = waEvent();
        $g = waGuest($event, ['name' => 'Ana', 'phone' => '+13055550123']);
        waGuest($event);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'all'])->assertStatus(202);

        $this->actingAs($u['owner'])->getJson("$api/messages")->assertJsonPath('meta.total', 4);
        $latest = $this->actingAs($u['owner'])->getJson("$api/messages?latest_per_guest=1")->assertJsonPath('meta.total', 2);
        expect(collect($latest->json('data'))->pluck('templateKey')->unique()->all())->toBe(['save_the_date']);
        $one = $this->actingAs($u['owner'])->getJson("$api/messages?guest_id={$g->id}&per_page=1")->json();
        expect($one['data'])->toHaveCount(1)->and($one['data'][0]['to'])->toBe('+13055550123')->and($one['data'][0]['guestName'])->toBe('Ana')
            ->and($one['meta'])->toBe(['page' => 1, 'perPage' => 1, 'total' => 2]);
        $this->actingAs($u['owner'])->getJson("$api/messages?per_page=101")->assertStatus(422);

        $this->actingAs($u['owner'])->getJson("$api/summary")->assertJsonPath('data', ['total' => 4, 'queued' => 0, 'sent' => 4, 'delivered' => 0, 'read' => 0, 'failed' => 0]);
    });
});
