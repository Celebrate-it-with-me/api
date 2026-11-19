<?php

use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** A template message of a family (own helper: the file does not depend on the load order of the others). */
function wpMsg($event, Guest $g, string $status = 'sent', string $key = 'invitation', array $extra = []): Message
{
    return Message::create(array_merge([
        'event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => $key, 'locale' => 'es', 'to_phone' => '+13055550100',
        'type' => 'template', 'status' => $status, 'queued_at' => now()->subDays(3),
    ], $extra));
}

/** A message of a batch. */
function waBatchMsg($event, Guest $g, string $batch, string $status = 'queued', array $extra = []): Message
{
    return wpMsg($event, $g, $status, 'invitation', array_merge(['batch_id' => $batch, 'queued_at' => now()->subMinutes(5)], $extra));
}

describe('preview', function () {
    it('renders the real message for a guest, with buttons, link and the data for the cover', function () {
        [$event, $u, $api] = waEvent();
        $ana = waGuest($event, ['name' => 'Ana López']);

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $ana->id])->assertOk()->json('data');

        expect($d['template'])->toBe('invitation')->and($d['locale'])->toBe('es')->and($d['category'])->toBe('marketing')
            ->and($d['guest'])->toBe(['id' => $ana->id, 'name' => 'Ana López'])->and($d['sample'])->toBeFalse()
            ->and($d['body'])->toContain('Ana López')->toContain('Los XV de Valentina')->toContain($d['link'])->not->toContain('{{')
            ->and($d['link'])->toContain('/i/' . $event->custom_url_slug . '/' . $ana->code)
            ->and($d['buttons'])->toBe([['id' => 'yes', 'text' => 'Sí, asistiré'], ['id' => 'no', 'text' => 'No podré asistir']])
            ->and($d['sendable'])->toBeTrue()
            ->and($d['linkPreview'])->toBe(['title' => 'Ana López, estás invitado/a a Los XV de Valentina', 'description' => $d['linkPreview']['description'], 'image' => config('app.frontend_app.url') . '/og-default.png', 'alt' => 'Quinvite', 'imageWidth' => 1200, 'imageHeight' => 630])
            ->and($d['linkPreview']['description'])->toEndWith('Abre tu invitación.')
            ->and($d['event']['id'])->toBe($event->id)->and($d['event']['name'])->toBe('Los XV de Valentina')->and($d['event']['published'])->toBeTrue()
            ->and($d['event']['startDate'])->toBeString()
            ->and($d)->not->toHaveKey('to')->and(json_encode($d))->not->toContain('305');
        expect(Message::count())->toBe(0);
    });

    it('uses the first family without guest_id, the guest locale by default and the asked locale when given', function () {
        [$event, $u, $api] = waEvent();
        $peter = waGuest($event, ['name' => 'Peter', 'locale' => 'en']);
        waGuest($event, ['name' => 'Zoe']);
        waGuest($event, ['name' => 'Companion', 'parent_id' => $peter->id]);

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'save_the_date'])->assertOk()->json('data');
        expect($d['guest']['id'])->toBe($peter->id)->and($d['locale'])->toBe('en')->and($d['buttons'])->toBe([]);

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $peter->id, 'locale' => 'es'])->assertOk()->json('data');
        expect($d['locale'])->toBe('es')->and($d['body'])->toContain('Peter')->and($d['body'])->toContain('invitamos');
    });

    it('shows a sample guest when the event has none', function () {
        [$event, $u, $api] = waEvent();

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation'])->assertOk()->json('data');
        expect($d['guest'])->toBeNull()->and($d['sample'])->toBeTrue()->and($d['body'])->toContain('Invitado')->and($d['linkPreview'])->toBeNull();

        $event->update(['locale' => 'en']);
        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation'])->assertOk()->json('data');
        expect($d['body'])->toContain('Guest');
    });

    it('draws the link card in the preview language, for a draft event too, without touching the rest of the response', function () {
        [$event, $u, $api] = waEvent();
        $peter = waGuest($event, ['name' => 'Peter', 'locale' => 'en']);

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $peter->id])->assertOk()->json('data');
        expect($d['locale'])->toBe('en')->and($d['linkPreview']['title'])->toBe('Peter, you are invited to Los XV de Valentina')
            ->and($d['linkPreview']['description'])->toEndWith('Open your invitation.')
            ->and(array_keys($d['linkPreview']))->toBe(['title', 'description', 'image', 'alt', 'imageWidth', 'imageHeight']);

        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $peter->id, 'locale' => 'es'])->assertOk()->json('data');
        expect($d['locale'])->toBe('es')->and($d['linkPreview']['title'])->toBe('Peter, estás invitado/a a Los XV de Valentina');

        $event->update(['status' => 'draft']);
        $d = $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $peter->id])->assertOk()->json('data');
        expect($d['linkPreview']['title'])->toContain('Peter')->and($d['event']['published'])->toBeFalse()
            ->and(array_keys($d))->toBe(['template', 'locale', 'category', 'guest', 'sample', 'body', 'buttons', 'link', 'sendable', 'event', 'linkPreview'])
            ->and(json_encode($d['linkPreview']))->not->toContain($peter->code)->not->toContain('305');
    });

    it('answers 404 for a guest of another event or a companion, 422 for bad input, 409 without an approved template', function () {
        [$event, $u, $api] = waEvent();
        [$other] = waEvent();
        $foreign = waGuest($other);
        $main = waGuest($event);
        $companion = waGuest($event, ['parent_id' => $main->id]);

        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $foreign->id])->assertNotFound();
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $companion->id])->assertNotFound();
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'padrino_reminder'])->assertStatus(422)->assertJsonValidationErrors('template');
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors('locale');
        $this->actingAs($u['owner'])->postJson("$api/preview", [])->assertStatus(422);

        Template::where('key', 'invitation')->where('locale', 'es')->update(['status' => 'rejected']);
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $main->id])->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        Template::where('key', 'invitation')->where('locale', 'es')->delete();
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation', 'guest_id' => $main->id])->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
    });

    it('lets owner and editor in (also on the free plan), and keeps viewers, strangers and anonymous out', function () {
        [$event, $u, $api] = waEvent('basic');
        waGuest($event);
        $editor = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $editor->id, 'role_id' => Role::where('name', 'editor')->value('id')]);

        $this->postJson("$api/preview", ['template' => 'invitation'])->assertUnauthorized();
        $this->actingAs($u['stranger'])->postJson("$api/preview", ['template' => 'invitation'])->assertNotFound();
        $this->actingAs($u['viewer'])->postJson("$api/preview", ['template' => 'invitation'])->assertForbidden();
        $this->actingAs($editor)->postJson("$api/preview", ['template' => 'invitation'])->assertOk();
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation'])->assertOk();
    });

    it('works with the whatsapp module off (it renders, never sends)', function () {
        [$event, $u, $api] = waEvent('premium', false);
        waGuest($event);
        $this->actingAs($u['owner'])->postJson("$api/preview", ['template' => 'invitation'])->assertOk();
    });
});

describe('batch progress', function () {
    it('counts each status once, with the messages, and says when it is done', function () {
        [$event, $u, $api] = waEvent();
        $b = (string) Str::uuid();
        $ana = waGuest($event, ['name' => 'Ana']);
        waBatchMsg($event, $ana, $b, 'read');
        waBatchMsg($event, waGuest($event, ['name' => 'Beto']), $b, 'delivered');
        waBatchMsg($event, waGuest($event), $b, 'sent');
        waBatchMsg($event, waGuest($event), $b, 'queued');
        $failed = waBatchMsg($event, waGuest($event, ['name' => 'Carla']), $b, 'failed', ['error_code' => '131026', 'error_message' => 'No WhatsApp', 'retry_count' => 1]);

        $d = $this->actingAs($u['owner'])->getJson("$api/batches/$b")->assertOk()->json('data');

        expect($d['batchId'])->toBe($b)->and($d['template'])->toBe('invitation')->and($d['total'])->toBe(5)
            ->and([$d['queued'], $d['sent'], $d['delivered'], $d['read'], $d['failed'], $d['cancelled']])->toBe([1, 1, 1, 1, 1, 0])
            ->and($d['processed'])->toBe(4)->and($d['done'])->toBeFalse()->and($d['startedAt'])->toBeString()
            ->and($d['messages'])->toHaveCount(5)->and($d['meta'])->toBe(['page' => 1, 'perPage' => 100, 'total' => 5])
            ->and($d['messages'][0])->toBe(['messageId' => Message::where('guest_id', $ana->id)->value('id'), 'guestId' => $ana->id, 'guestName' => 'Ana', 'status' => 'read', 'errorCode' => null, 'errorMessage' => null, 'retryCount' => 0])
            ->and(collect($d['messages'])->firstWhere('messageId', $failed->id))->toMatchArray(['guestName' => 'Carla', 'errorCode' => '131026', 'retryCount' => 1]);

        Message::where('batch_id', $b)->where('status', 'queued')->update(['status' => 'sent']);
        expect($this->actingAs($u['owner'])->getJson("$api/batches/$b")->json('data.done'))->toBeTrue();
    });

    it('paginates the messages', function () {
        [$event, $u, $api] = waEvent();
        $b = (string) Str::uuid();
        foreach (range(1, 3) as $i) {
            waBatchMsg($event, waGuest($event), $b, 'sent');
        }

        $d = $this->actingAs($u['owner'])->getJson("$api/batches/$b?per_page=2&page=2")->assertOk()->json('data');
        expect($d['messages'])->toHaveCount(1)->and($d['meta'])->toBe(['page' => 2, 'perPage' => 2, 'total' => 3])->and($d['total'])->toBe(3);
        $this->actingAs($u['owner'])->getJson("$api/batches/$b?per_page=500")->assertStatus(422);
    });

    it('is a 404 for a batch that is unknown, of another event, or not a uuid', function () {
        [$event, $u, $api] = waEvent();
        [$other, $u2] = waEvent();
        $b = (string) Str::uuid();
        waBatchMsg($other, waGuest($other), $b, 'sent');

        $this->actingAs($u['owner'])->getJson("$api/batches/$b")->assertNotFound();
        $this->actingAs($u['owner'])->getJson("$api/batches/" . Str::uuid())->assertNotFound();
        $this->actingAs($u['owner'])->getJson("$api/batches/nope")->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$api/batches/$b")->assertNotFound();
    });

    it('finds the active batch, newest first, and answers null when nothing is queued', function () {
        [$event, $u, $api] = waEvent();
        $this->actingAs($u['owner'])->getJson("$api/batches/active")->assertOk()->assertJsonPath('data', null);

        $old = (string) Str::uuid();
        $new = (string) Str::uuid();
        $finished = (string) Str::uuid();
        waBatchMsg($event, waGuest($event), $old, 'queued');
        waBatchMsg($event, waGuest($event), $new, 'queued');
        waBatchMsg($event, waGuest($event), $new, 'sent');
        waBatchMsg($event, waGuest($event), $finished, 'sent');
        // A message without a batch (a session reply) never makes a batch.
        wpMsg($event, waGuest($event), 'queued');

        $res = $this->actingAs($u['owner'])->getJson("$api/batches/active")->assertOk();
        expect($res->json('data.batchId'))->toBe($new)->and($res->json('data.total'))->toBe(2)->and($res->json('data.done'))->toBeFalse();

        Message::whereNotNull('batch_id')->update(['status' => 'sent']);
        Message::whereNull('batch_id')->update(['status' => 'sent']);
        $this->actingAs($u['owner'])->getJson("$api/batches/active")->assertJsonPath('data', null);
    });

    it('ignores the queued batches of other events and lets viewers read on the free plan', function () {
        [$event, $u, $api] = waEvent('basic');
        [$other] = waEvent();
        waBatchMsg($other, waGuest($other), (string) Str::uuid(), 'queued');

        $this->getJson("$api/batches/active")->assertUnauthorized();
        $this->actingAs($u['viewer'])->getJson("$api/batches/active")->assertOk()->assertJsonPath('data', null);
        $this->actingAs($u['stranger'])->getJson("$api/batches/active")->assertNotFound();
    });

    it('follows a real send through the fake driver', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        waGuest($event);
        $batch = $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202)->json('data.batchId');

        $d = $this->actingAs($u['owner'])->getJson("$api/batches/$batch")->json('data');
        expect($d['total'])->toBe(2)->and($d['done'])->toBeTrue()->and($d['sent'])->toBe(2);
    });
});

describe('big batches (P7)', function () {
    it('asks for confirmation above 100 families and queues nothing until it comes', function () {
        [$event, $u, $api] = waEvent();
        Guest::factory()->count(101)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550123', 'rsvp_status' => 'pending']);
        $body = ['template' => 'invitation', 'audience' => 'all'];

        $this->actingAs($u['owner'])->postJson("$api/send", $body)->assertStatus(409)
            ->assertJsonPath('code', 'confirmation_required')->assertJsonPath('count', 101)->assertJsonPath('threshold', 100);
        expect(Message::count())->toBe(0);

        // The dry run never asks, but tells the wizard to.
        $this->actingAs($u['owner'])->postJson("$api/send", $body + ['dry_run' => true])->assertOk()->assertJsonPath('data.requiresConfirmation', true)->assertJsonPath('data.queued', 101);

        $this->actingAs($u['owner'])->postJson("$api/send", $body + ['confirm_large' => true])->assertStatus(202)->assertJsonPath('data.queued', 101);
    });

    it('does not ask at exactly 100 families, and counts what would be queued, not the skipped', function () {
        [$event, $u, $api] = waEvent();
        Guest::factory()->count(100)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550123']);
        Guest::factory()->count(20)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '']);

        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all', 'dry_run' => true])->assertJsonPath('data.requiresConfirmation', false);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202)->assertJsonPath('data.queued', 100);
    });
});

describe('reminders (P6)', function () {
    it('stops at three reminders per family, even when forced, and counts them in the audience', function () {
        [$event, $u, $api] = waEvent();
        $g = waGuest($event, ['name' => 'Insistida']);
        $fresh = waGuest($event, ['name' => 'Nueva']);
        foreach ([5, 4, 3] as $days) {
            wpMsg($event, $g, 'sent', 'reminder_pending', ['queued_at' => now()->subDays($days)]);
        }
        // A failed reminder never reached her: it does not count.
        wpMsg($event, $fresh, 'failed', 'reminder_pending', ['queued_at' => now()->subDays(6)]);
        $body = ['template' => 'reminder_pending', 'audience' => 'all'];

        $res = $this->actingAs($u['owner'])->postJson("$api/send", $body + ['force' => true, 'dry_run' => true])->assertOk();
        expect($res->json('data.queued'))->toBe(1)->and($res->json('data.skipped'))->toBe([['guestId' => $g->id, 'guestName' => 'Insistida', 'reason' => 'reminder_limit']]);

        $a = $this->actingAs($u['owner'])->getJson("$api/audience?template=reminder_pending")->json('data');
        expect($a['blocked']['reminder_limit'])->toBe(1)->and($a['categories']['all']['count'])->toBe(1);

        $r = collect($this->actingAs($u['owner'])->getJson("$api/recipients?template=reminder_pending")->json('data'))->firstWhere('id', $g->id);
        expect($r['eligible'])->toBeFalse()->and($r['reason'])->toBe('reminder_limit');
    });

    it('keeps the 24 h wait, lets a second reminder through after it and skips those who answered', function () {
        [$event, $u, $api] = waEvent();
        $waited = waGuest($event);
        $recent = waGuest($event);
        $answered = waGuest($event, ['rsvp_status' => 'attending']);
        wpMsg($event, $waited, 'sent', 'reminder_pending', ['queued_at' => now()->subDays(2)]);
        wpMsg($event, $recent, 'sent', 'reminder_pending', ['queued_at' => now()->subHours(2)]);
        $body = ['template' => 'reminder_pending', 'audience' => 'all'];

        $d = $this->actingAs($u['owner'])->postJson("$api/send", $body)->assertStatus(202)->json('data');
        expect($d['queued'])->toBe(1)
            ->and(collect($d['skipped'])->pluck('reason', 'guestId')->all())->toBe([$recent->id => 'recently_sent', $answered->id => 'already_responded']);
    });

    it('does not apply the reminder ceiling to other templates', function () {
        [$event, $u, $api] = waEvent();
        $g = waGuest($event);
        foreach ([5, 4, 3] as $days) {
            wpMsg($event, $g, 'sent', 'reminder_pending', ['queued_at' => now()->subDays($days)]);
        }
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'all'])->assertStatus(202)->assertJsonPath('data.queued', 1);
    });
});

describe('send permissions (P10)', function () {
    it('sends only for owner and editor, under the whatsapp module, and reads under guests', function () {
        [$event, $u, $api] = waEvent('basic');
        waGuest($event);
        $editor = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $editor->id, 'role_id' => Role::where('name', 'editor')->value('id')]);
        $body = ['template' => 'invitation', 'audience' => 'all'];

        // Free plan: the reads work, the automatic send is locked for everybody.
        foreach (['audience', 'recipients', 'batches/active'] as $read) {
            $this->actingAs($u['viewer'])->getJson("$api/$read")->assertOk();
        }
        $this->actingAs($u['owner'])->postJson("$api/send", $body)->assertForbidden()->assertJsonPath('code', 'module_locked');

        [$event, $u, $api] = waEvent();
        waGuest($event);
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $editor->id, 'role_id' => Role::where('name', 'editor')->value('id')]);
        $this->actingAs($u['viewer'])->postJson("$api/send", $body)->assertForbidden();
        $this->actingAs($editor)->postJson("$api/send", $body)->assertStatus(202);
        $this->actingAs($u['owner'])->postJson("$api/send", $body + ['force' => true])->assertStatus(202);
    });

    it('blocks the automatic send of an unpublished event, even the save the date', function () {
        [$event, $u, $api] = waEvent();
        waGuest($event);
        $event->update(['status' => 'draft']);
        $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'save_the_date', 'audience' => 'all'])->assertStatus(409)->assertJsonPath('code', 'event_not_published');
    });
});
