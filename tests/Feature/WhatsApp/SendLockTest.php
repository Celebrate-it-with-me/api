<?php

use App\Http\Services\WhatsApp\FakeGateway;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Models\WhatsApp\Message;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['services.whatsapp.send_lock_wait' => 0]);
});

describe('send lock per event (M-4)', function () {
    it('answers 409 send_in_progress and queues nothing while another send holds the event', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        $held = Cache::lock('whatsapp-send-event:' . $event->id, 30);
        expect($held->get())->toBeTrue();

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])
            ->assertStatus(409)->assertJsonPath('code', 'send_in_progress');
        expect(Message::count())->toBe(0);

        $held->release();
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        expect(Message::count())->toBe(1);
    });

    it('does not block a different event', function () {
        [$event] = waEvent();
        [$other, $u, $api] = waEvent();
        waGuest($other);
        $held = Cache::lock('whatsapp-send-event:' . $event->id, 30);
        $held->get();

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $held->release();
    });

    it('frees the lock when the send is refused, so the next one is not stuck', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        config(['services.whatsapp.max_daily_per_event' => 0]);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(429);

        config(['services.whatsapp.max_daily_per_event' => 10]);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
    });

    it('never passes the daily cap with consecutive sends of different guests', function () {
        [$event, $u, $api] = waEvent();
        config(['services.whatsapp.max_daily_per_event' => 2]);
        $ids = collect(range(1, 4))->map(fn () => waGuest($event)->id);

        $a = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'selected', 'guest_ids' => [$ids[0], $ids[1], $ids[2]]])->assertStatus(202);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'selected', 'guest_ids' => [$ids[3]]])->assertStatus(429);

        expect($a->json('data.queued'))->toBe(2)->and(Message::count())->toBe(2);
    });

    it('answers 409 send_in_progress to a retry while the event is locked, and retries once released', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        FakeGateway::failNext(new WhatsAppException('131026', 'Message undeliverable', false));
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $m = Message::first();
        expect($m->status)->toBe('failed');

        $held = Cache::lock('whatsapp-send-event:' . $event->id, 30);
        $held->get();
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(409)->assertJsonPath('code', 'send_in_progress');
        expect($m->fresh()->status)->toBe('failed')->and($m->fresh()->retry_count)->toBe(0);
        $held->release();

        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(202);
        expect($m->fresh()->retry_count)->toBe(1);
    });

    it('retries a failed message only once even if the same request repeats', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        FakeGateway::failNext(new WhatsAppException('131026', 'Message undeliverable', false));
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);
        $m = Message::first();
        // The second click (another tab) finds it already retried: 409, and the count moves only once.
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(202);
        $this->actingAs($u['owner'])->postJson("$api/messages/{$m->id}/retry")->assertStatus(409)->assertJsonPath('code', 'not_failed');
        expect($m->fresh()->retry_count)->toBe(1);
    });
});
