<?php

use App\Http\Services\WhatsApp\DailyLimit;
use App\Http\Services\WhatsApp\FakeGateway;
use App\Http\Services\WhatsApp\MessageComposer;
use App\Http\Services\WhatsApp\MessageStatus;
use App\Http\Services\WhatsApp\MetaGateway;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Jobs\SendWhatsAppMessage;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\Guest;
use App\Models\User;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\OptOut;
use App\Models\WhatsApp\Template;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__ . '/../EventAccessTest.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me']);
});

describe('W-01 volume caps and sanitized variables', function () {
    it('stops forced resends at the daily cap of the event', function () {
        [$event, $u, $api] = waEvent();
        config(['services.whatsapp.max_daily_per_event' => 5]);
        foreach (range(1, 3) as $_) {
            waGuest($event);
        }

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202)->assertJsonPath('data.queued', 3);
        $res = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'force' => true])->assertStatus(202);
        expect($res->json('data.queued'))->toBe(2)->and(collect($res->json('data.skipped'))->pluck('reason')->all())->toBe(['daily_limit']);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'force' => true])
            ->assertStatus(429)->assertJsonPath('code', 'whatsapp_daily_limit');
        expect(Message::count())->toBe(5);
    });

    it('applies the account cap across the events of the same owner', function () {
        [$event, $u, $api] = waEvent();
        config(['services.whatsapp.max_daily_per_account' => 2]);
        waGuest($event);
        waGuest($event);
        waGuest($event);

        $res = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        expect($res->json('data.queued'))->toBe(2)->and($res->json('data.skipped.0.reason'))->toBe('daily_limit');
    });

    it('defaults the event cap to 3x the guests with a phone, at least 100', function () {
        [$event] = waEvent();
        config(['services.whatsapp.max_daily_per_event' => null]);
        waGuest($event);
        expect(app(DailyLimit::class)->eventLimit($event))->toBe(100);
    });

    it('caps retries per message and counts them against the daily limit', function () {
        [$event, $u, $api] = waEvent();
        $g = waGuest($event);
        $m = Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'failed', 'retry_count' => 3]);
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(409)->assertJsonPath('code', 'retry_limit');

        $m->update(['retry_count' => 0, 'queued_at' => now()]);
        config(['services.whatsapp.max_daily_per_event' => 0]);
        Message::create(['event_id' => $event->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550124', 'status' => 'sent', 'queued_at' => now()]);
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(429)->assertJsonPath('code', 'whatsapp_daily_limit');
    });

    it('strips links, e-mails and line breaks from free names before they reach the template', function () {
        [$event, $u, $api] = waEvent();
        $event->update(['event_name' => "Fiesta\n\n   gana en www.premio.com ahora"]);
        waGuest($event, ['name' => "Ana https://evil.example/x?y=1\r\nllama a bit.ly/abc o a mala@x.com   ya"]);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $params = Message::first()->params;
        expect($params['guest_name'])->toBe('Ana llama a o a ya')->and($params['event_name'])->toBe('Fiesta gana en ahora')
            ->and($params['link'])->toStartWith(rtrim(config('app.frontend_app.url'), '/') . '/i/');
    });

    it('limits names to 60 characters', function () {
        [$event] = waEvent();
        $g = waGuest($event, ['name' => str_repeat('a', 200)]);
        expect(mb_strlen(app(MessageComposer::class)->params('invitation', $event, $g, 'es')['guest_name']))->toBeLessThanOrEqual(60);
    });
});

describe('W-02 opt-out by phone', function () {
    it('does not message a number again after STOP, even if the guest is recreated', function () {
        [$event, $u, $api] = waEvent();
        $guest = waGuest($event, ['phone' => '+13055550123']);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);

        waWebhook($this, waInboundPayload(['from' => '13055550123', 'type' => 'text', 'text' => ['body' => 'STOP']]))->assertOk();
        expect(OptOut::where('phone_e164', '+13055550123')->exists())->toBeTrue();

        $guest->delete();
        $again = waGuest($event, ['phone' => '(305) 555-0123']);
        expect($again->whatsapp_opted_out_at)->not->toBeNull();

        $res = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'force' => true])->assertStatus(202);
        expect($res->json('data.queued'))->toBe(0)->and($res->json('data.skipped.0.reason'))->toBe('opted_out');
    });

    it('records a STOP from a number we never messaged, and the sender checks the table', function () {
        [$event, $u, $api] = waEvent();
        waWebhook($this, waInboundPayload(['from' => '13055550199', 'type' => 'text', 'text' => ['body' => 'baja']]))->assertOk();
        expect(OptOut::has('305-555-0199'))->toBeTrue();

        // A message queued before the STOP is stopped by the job.
        $g = Guest::withoutEvents(fn () => waGuest($event, ['phone' => '+13055550199']));
        $m = Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550199', 'status' => 'queued']);
        SendWhatsAppMessage::dispatchSync($m->id);
        expect($m->refresh()->status)->toBe('failed')->and($m->error_code)->toBe('opted_out')->and(FakeGateway::$sent)->toBe([]);
    });
});

describe('W-03 collaborator invitations', function () {
    it('throttles invitations per user and per event and cleans the event name in the mail', function () {
        [$event, $m] = eventWithMembers();
        $event->update(['event_name' => 'Gana https://evil.example ya']);
        Mail::fake();
        $url = "/api/v1/app/event/{$event->id}/collaborators";

        $this->actingAs($m['owner'])->postJson($url, ['email' => 'a@x.test', 'role' => 'viewer'])->assertOk();
        Mail::assertSent(InviteUnregisteredUserMail::class, fn ($mail) => $mail->envelope()->subject !== '' && ! str_contains($mail->envelope()->subject, 'evil.example'));

        foreach (range(1, 9) as $i) {
            $this->actingAs($m['owner'])->postJson($url, ['email' => "u$i@x.test", 'role' => 'viewer'])->assertOk();
        }
        $this->actingAs($m['owner'])->postJson($url, ['email' => 'late@x.test', 'role' => 'viewer'])->assertStatus(429);
    });

    it('ignores closed invitations on the public check and validates the batch of tokens', function () {
        [$event, $m] = eventWithMembers();
        $done = EventCollaborationInvite::create(['event_id' => $event->id, 'email' => 'x@x.test', 'role' => 'viewer', 'token' => EventCollaborationInvite::generateToken(),
            'status' => 'accepted', 'invited_by_user_id' => $m['owner']->id, 'expires_at' => now()->addDay()]);

        $this->getJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$done->token}")->assertStatus(422);
        $this->getJson('/api/v1/app/collaborators/check-tokens?tokens=notanarray')->assertStatus(422);
        $this->getJson('/api/v1/app/collaborators/check-tokens?tokens[]=nope')->assertStatus(422);
    });
});

describe('W-04 reset-password', function () {
    it('does not let a stranger lock the real owner out, and ends cookie sessions after a reset', function () {
        $user = User::factory()->create();
        $bad = ['email' => $user->email, 'password' => 'NewPass12345', 'password_confirmation' => 'NewPass12345', 'token' => 'junk'];

        foreach (range(1, 5) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/app/reset-password', $bad)->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/app/reset-password', $bad)->assertStatus(429);

        config(['session.driver' => 'database']);
        $token = Password::broker()->createToken($user);
        DB::table('sessions')->insert(['id' => 'sess1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/app/reset-password', ['token' => $token] + $bad)->assertOk();
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    });
});

describe('W-05 Graph paging URL', function () {
    beforeEach(fn () => config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1098', 'services.whatsapp.business_account_id' => '2055',
        'services.whatsapp.access_token' => 'TOKEN', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v']));

    it('never sends the token to another host or over http', function (string $next) {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => [], 'paging' => ['next' => $next]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        expect(fn () => (new MetaGateway)->fetchTemplates())->toThrow(WhatsAppException::class);
        Http::assertSentCount(1);
    })->with(['https://evil.example/steal', 'http://graph.facebook.com/v26.0/x', 'https://graph.facebook.com.evil.example/x', 'https://user@evil.example/x']);
});

describe('W-06 no duplicate sends after an unknown outcome', function () {
    it('does not resend a message whose delivery is unknown, and marks it for a manual retry', function () {
        [$event] = waEvent();
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1098', 'services.whatsapp.business_account_id' => '2055',
            'services.whatsapp.access_token' => 'T', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v']);
        Template::query()->update(['status' => 'approved']);
        $g = waGuest($event);
        $m = Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'queued', 'params' => []]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timeout');
        });
        SendWhatsAppMessage::dispatchSync($m->id);
        expect($m->refresh()->status)->toBe('failed')->and($m->error_code)->toBe('unknown_delivery');

        // A second worker with the same job finds nothing to do.
        SendWhatsAppMessage::dispatchSync($m->id);
        expect($calls)->toBe(1);
    });

    it('lets only one worker hand a message over', function () {
        [$event] = waEvent();
        $g = waGuest($event);
        $m = Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'queued', 'send_started_at' => now()]);
        SendWhatsAppMessage::dispatchSync($m->id);
        expect(FakeGateway::$sent)->toBe([])->and($m->refresh()->status)->toBe('queued');
    });
});

describe('W-07 public invitation endpoints are throttled', function () {
    it('answers 429 after 30 checks per minute', function () {
        [$event] = eventWithMembers();
        RateLimiter::clear('x');
        foreach (range(1, 30) as $_) {
            $this->getJson("/api/v1/app/event/{$event->id}/collaborators/invite/abc")->assertStatus(422);
        }
        $this->getJson("/api/v1/app/event/{$event->id}/collaborators/invite/abc")->assertStatus(429);
    });
});

describe('W-09 early delivery statuses', function () {
    it('keeps a delivered that beats the stored message id and applies it when the id is saved', function () {
        [$event] = waEvent();
        $g = waGuest($event);
        $m = Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => 'invitation', 'locale' => 'es', 'to_phone' => '+13055550123', 'status' => 'queued']);

        waWebhook($this, waStatusPayload('wamid.EARLY', 'delivered'))->assertOk();
        expect($m->refresh()->status)->toBe('queued')->and(DB::table('whatsapp_pending_statuses')->count())->toBe(1)
            ->and(DB::table('whatsapp_inbound_events')->where('event_key', 'like', 'st:wamid.EARLY%')->count())->toBe(0);

        app(MessageStatus::class)->sent($m, 'wamid.EARLY');
        expect($m->refresh()->status)->toBe('delivered')->and($m->delivered_at)->not->toBeNull()->and(DB::table('whatsapp_pending_statuses')->count())->toBe(0);
    });
});
