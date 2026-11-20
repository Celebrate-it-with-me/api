<?php

use App\Http\Services\WhatsApp\FakeGateway;
use App\Http\Services\WhatsApp\WhatsAppBroadcaster;
use App\Models\Guest;
use App\Models\GuestRsvpLog;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me']);
});

/** A sent message to a guest, ready to receive webhook events. */
function waSent(array $guest = [], string $status = 'sent'): array
{
    [$event, $u, $api] = waEvent();
    $g = waGuest($event, $guest + ['phone' => '+13055550123']);
    $m = Message::create([
        'event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123',
        'status' => $status, 'wa_message_id' => 'wamid.ABC' . uniqid(), 'queued_at' => now(), 'sent_at' => now(),
    ]);

    return [$event, $g, $m];
}

describe('verification and signature', function () {
    it('answers the challenge only with the right token', function () {
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()->assertSee('12345', false)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=12345')->assertForbidden();
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=other&hub.verify_token=verify-me&hub.challenge=1')->assertForbidden();
        config(['services.whatsapp.verify_token' => '']);
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    });

    it('rejects an invalid or missing signature without processing', function () {
        [, , $m] = waSent();
        $payload = waStatusPayload($m->wa_message_id, 'delivered');

        waWebhook($this, $payload, 'sha256=deadbeef')->assertForbidden();
        waWebhook($this, $payload, waSign(json_encode($payload), 'wrong-secret'))->assertForbidden();
        $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertForbidden();
        expect($m->refresh()->status)->toBe('sent');

        config(['services.whatsapp.app_secret' => '']);
        waWebhook($this, $payload, waSign(json_encode($payload), ''))->assertForbidden();
    });
});

describe('statuses', function () {
    it('moves a message forward and never backwards', function () {
        [, , $m] = waSent();

        waWebhook($this, waStatusPayload($m->wa_message_id, 'read'))->assertOk();
        expect($m->refresh()->status)->toBe('read')->and($m->read_at)->not->toBeNull()->and($m->delivered_at)->not->toBeNull();

        // A late `delivered` and `sent` do not step back.
        waWebhook($this, waStatusPayload($m->wa_message_id, 'delivered'))->assertOk();
        waWebhook($this, waStatusPayload($m->wa_message_id, 'sent'))->assertOk();
        expect($m->refresh()->status)->toBe('read');
    });

    it('is idempotent: the same event twice changes nothing more', function () {
        [, , $m] = waSent();
        $payload = waStatusPayload($m->wa_message_id, 'delivered');

        waWebhook($this, $payload)->assertOk();
        $at = $m->refresh()->delivered_at;
        $this->travel(5)->minutes();
        waWebhook($this, $payload)->assertOk();

        expect($m->refresh()->delivered_at->equalTo($at))->toBeTrue()
            ->and(DB::table('whatsapp_inbound_events')->count())->toBe(1);
    });

    it('records a failure with its code, and a failed message later delivered recovers', function () {
        [, , $m] = waSent();
        waWebhook($this, waStatusPayload($m->wa_message_id, 'failed', ['errors' => [['code' => 131026, 'title' => 'Undeliverable', 'message' => 'Message undeliverable']]]))->assertOk();
        expect($m->refresh())->status->toBe('failed')->error_code->toBe('131026')->error_message->toBe('Message undeliverable');

        // A delivered message is never turned into a failure.
        [, , $d] = waSent([], 'delivered');
        waWebhook($this, waStatusPayload($d->wa_message_id, 'failed', ['errors' => [['code' => 1, 'title' => 'x']]]))->assertOk();
        expect($d->refresh()->status)->toBe('delivered');
    });

    it('ignores statuses of unknown messages', function () {
        waWebhook($this, waStatusPayload('wamid.UNKNOWN', 'read'))->assertOk();
        expect(Message::count())->toBe(0);
    });
});

describe('replies', function () {
    it('records the RSVP from a template button through the invitation service and confirms in session', function () {
        [$event, $g, $m] = waSent(['name' => 'Ana']);
        $payload = waInboundPayload(['from' => '13055550123', 'type' => 'button', 'button' => ['payload' => "rsvp:{$g->id}:yes", 'text' => 'Sí, asistiré']]);

        waWebhook($this, $payload)->assertOk();

        expect($g->refresh()->rsvp_status)->toBe('attending')->and($g->rsvp_status_date)->not->toBeNull();
        expect(GuestRsvpLog::where('guest_id', $g->id)->where('status', 'attending')->count())->toBe(1);
        $reply = collect(FakeGateway::$sent)->firstWhere('type', 'text');
        expect($reply['to'])->toBe('+13055550123')->and($reply['text'])->toContain('Ana')->and($reply['text'])->toContain('Los XV de Valentina');
        expect(Message::where('type', 'text')->where('template_key', 'rsvp_confirmation')->value('status'))->toBe('sent');

        // Replaying the very same webhook does not duplicate anything.
        waWebhook($this, $payload)->assertOk();
        expect(GuestRsvpLog::where('guest_id', $g->id)->count())->toBe(1)->and(Message::where('type', 'text')->count())->toBe(1);
    });

    it('records a no from an interactive button reply', function () {
        [, $g] = waSent();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "rsvp:{$g->id}:no", 'title' => 'No']]]))->assertOk();
        expect($g->refresh()->rsvp_status)->toBe('not-attending');
    });

    it('ignores a payload from a number that never got a message for that guest', function () {
        [, $g] = waSent();
        waWebhook($this, waInboundPayload(['from' => '13055559999', 'type' => 'button', 'button' => ['payload' => "rsvp:{$g->id}:yes", 'text' => 'x']]))->assertOk();
        expect($g->refresh()->rsvp_status)->toBe('pending')->and(FakeGateway::$sent)->toBe([]);
    });

    it('falls back to the phone and the button text when there is no valid payload, and does nothing when it is ambiguous', function () {
        [$event, $g] = waSent();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'button', 'button' => ['payload' => 'garbage', 'text' => 'Sí, asistiré']]))->assertOk();
        expect($g->refresh()->rsvp_status)->toBe('attending');

        // A second guest with messages to the same number: nobody can be told apart.
        $g2 = waGuest($event, ['phone' => '+13055550123']);
        Message::create(['event_id' => $event->id, 'guest_id' => $g2->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'sent']);
        $g->forceFill(['rsvp_status' => 'pending'])->save();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'button', 'button' => ['payload' => 'garbage', 'text' => 'Sí, asistiré']]))->assertOk();
        expect($g->refresh()->rsvp_status)->toBe('pending')->and($g2->refresh()->rsvp_status)->toBe('pending');
    });

    it('does not change the RSVP once responses are closed', function () {
        [$event, $g] = waSent();
        $event->forceFill(['invitation_stage' => 'save_the_date'])->save();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'button', 'button' => ['payload' => "rsvp:{$g->id}:yes", 'text' => 'x']]))->assertOk();
        expect($g->refresh()->rsvp_status)->toBe('pending')->and(FakeGateway::$sent)->toBe([]);
    });
});

describe('opt-out', function (): void {
    it('marks the guest on STOP / BAJA / PARAR / NO MÁS in any case, and stops future sends', function (string $word) {
        [$event, $g] = waSent();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'text', 'text' => ['body' => $word]]))->assertOk();
        expect($g->refresh()->whatsapp_opted_out_at)->not->toBeNull();

        $res = app(WhatsAppBroadcaster::class)->send($event, 'save_the_date', 'selected', [$g->id]);
        expect($res['queued'])->toBe(0)->and($res['skipped'][0]['reason'])->toBe('opted_out');
    })->with(['STOP', 'stop', 'Baja', 'PARAR', 'No más', 'NO MAS', ' stop. ']);

    it('does not opt out on ordinary text', function () {
        [, $g] = waSent();
        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'text', 'text' => ['body' => 'no puedo parar de reír']]))->assertOk();
        expect($g->refresh()->whatsapp_opted_out_at)->toBeNull();
    });
});

describe('template review pushed by Meta', function () {
    it('updates the template status from message_template_status_update', function () {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'REJECTED', 'message_template_id' => 55, 'message_template_name' => 'quinvite_invitation_es', 'message_template_language' => 'es', 'reason' => 'INVALID_FORMAT',
        ]]]]]];
        waWebhook($this, $payload)->assertOk();
        $t = Template::where('meta_name', 'quinvite_invitation_es')->first();
        expect($t->status)->toBe('rejected')->and($t->rejection_reason)->toBe('INVALID_FORMAT');
    });
});

describe('whatsapp:simulate', function () {
    it('drives the same paths as the webhook', function () {
        [$event, $g, $m] = waSent();
        $run = fn (string $a) => Artisan::call('whatsapp:simulate', ['messageId' => $m->id, 'action' => $a]);

        expect($run('delivered'))->toBe(0)->and($m->refresh()->status)->toBe('delivered');
        $run('read');
        expect($m->refresh()->status)->toBe('read');
        $run('reply-yes');
        expect($g->refresh()->rsvp_status)->toBe('attending');
        $run('reply-no');
        expect($g->refresh()->rsvp_status)->toBe('not-attending');
        $run('stop');
        expect($g->refresh()->whatsapp_opted_out_at)->not->toBeNull();

        [, , $m2] = waSent();
        Artisan::call('whatsapp:simulate', ['messageId' => $m2->id, 'action' => 'failed']);
        expect($m2->refresh()->status)->toBe('failed')->and($m2->error_code)->toBe('131026');
    });

    it('refuses to run with the meta driver or an unknown action', function () {
        [, , $m] = waSent();
        expect(Artisan::call('whatsapp:simulate', ['messageId' => $m->id, 'action' => 'bogus']))->toBe(1);
        config(['services.whatsapp.driver' => 'meta']);
        expect(Artisan::call('whatsapp:simulate', ['messageId' => $m->id, 'action' => 'read']))->toBe(1)->and($m->refresh()->status)->toBe('sent');
    });
});

it('does not hand Meta the api limiter and only throttles unsigned posts strictly (P-2)', function () {
    $payload = ['entry' => []];
    // Signed requests are not held to the 120/min `api` limit nor the unsigned one.
    foreach (range(1, 130) as $_) {
        waWebhook($this, $payload)->assertOk();
    }
    // Malformed signature header: refused (403, as for any bad signature) without computing the HMAC.
    foreach (['sha256=zz', 'nope', 'sha256=' . str_repeat('a', 63)] as $bad) {
        waWebhook($this, $payload, $bad)->assertForbidden();
    }
    // A well-shaped but wrong one still dies in the controller's check.
    waWebhook($this, $payload, 'sha256=' . str_repeat('a', 64))->assertForbidden();
});

it('throttles unsigned posts at the strict limit and caps the body (P-2)', function () {
    foreach (range(1, 60) as $_) {
        expect($this->postJson('/api/v1/webhooks/whatsapp', [])->status())->toBe(403);
    }
    $this->postJson('/api/v1/webhooks/whatsapp', [])->assertStatus(429);
});

it('refuses an oversized signed body with 413 (P-2)', function () {
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . str_repeat('a', 64)], str_repeat('x', 1024 * 1024 + 1))
        ->assertStatus(413);
});
