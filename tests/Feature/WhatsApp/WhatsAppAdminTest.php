<?php

use App\Http\Services\WhatsApp\FakeGateway;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Models\AdminAuditLog;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use App\Support\AdminPermissions;
use App\Support\WhatsApp\TemplateCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn ($u) => $u->syncRoles([]));
    FakeGateway::reset();
    config(['services.whatsapp.driver' => 'fake']);
});

const WA_ADMIN = '/api/v1/admin/whatsapp';

function waMeta(): void
{
    config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '109876541234', 'services.whatsapp.business_account_id' => '205566775678',
        'services.whatsapp.access_token' => 'EAAB-secret-token', 'services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me']);
}

it('seeds the five templates in both languages as drafts, with the fixed variables', function () {
    $rows = Template::all();
    expect($rows)->toHaveCount(10)->and($rows->pluck('status')->unique()->all())->toBe(['draft'])
        ->and(Template::where('key', 'invitation')->where('locale', 'es')->first()->variables)->toBe(['guest_name', 'event_name', 'event_date', 'link'])
        ->and(Template::where('key', 'padrino_reminder')->first()->variables)->toBe(['padrino_name', 'event_name', 'item_name', 'amount', 'due_date']);
    // Every shipped body uses exactly its variables, in order, and never starts or ends with one (Meta rule).
    foreach ($rows as $t) {
        expect(TemplateCatalog::placeholders($t->body))->toBe(TemplateCatalog::expectedMarkers($t->variables))
            ->and($t->body)->not->toStartWith('{{')->and($t->body)->not->toEndWith('}}')
            ->and(mb_strlen($t->body))->toBeLessThanOrEqual(1024);
    }
    expect(Template::where('key', 'invitation')->first()->body)->toContain('{{1}}')->not->toContain('{{guest_name}}');
    expect(Module::where('key', 'whatsapp')->value('is_enabled'))->toBeFalse();
});

describe('permissions', function () {
    it('keeps non-staff out, lets support read, and reserves writes for the superadmin with reauth', function () {
        $reads = ['status', 'summary', 'messages', 'templates'];
        foreach ($reads as $r) {
            $this->app['auth']->forgetGuards();
            $this->getJson(WA_ADMIN . "/$r")->assertUnauthorized();
            $this->actingAs(User::factory()->create())->getJson(WA_ADMIN . "/$r")->assertForbidden();
            $this->actingAs(waStaff('support'))->getJson(WA_ADMIN . "/$r")->assertOk();
        }
        $id = Template::first()->id;
        $writes = [['put', "/templates/$id", ['body' => 'x', 'reason' => 'edit it please']], ['post', "/templates/$id/submit", ['reason' => 'submit it']],
            ['post', '/templates/sync', ['reason' => 'sync them']], ['post', '/test', ['to' => '+13055550123', 'template_key' => 'invitation', 'locale' => 'es', 'reason' => 'a test run']]];
        foreach ($writes as [$m, $path, $body]) {
            $this->actingAsReauthed(waStaff('support'))->{$m . 'Json'}(WA_ADMIN . $path, $body)->assertForbidden();
            // Superadmin without a fresh re-authentication.
            $this->actingAs(waStaff())->{$m . 'Json'}(WA_ADMIN . $path, $body)->assertForbidden()->assertJsonPath('code', 'reauth_required');
        }
    });

    it('requires a reason of at least 5 characters', function () {
        $id = Template::first()->id;
        $this->actingAsReauthed(waStaff())->postJson(WA_ADMIN . "/templates/$id/submit", ['reason' => 'no'])->assertStatus(422)->assertJsonValidationErrors('reason');
    });
});

describe('status, summary and messages', function () {
    it('reports the connection without leaking secrets', function () {
        $admin = waStaff();
        $this->actingAs($admin)->getJson(WA_ADMIN . '/status')->assertOk()->assertJsonPath('data.driver', 'fake')->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.lastWebhookAt', null);

        waMeta();
        $res = $this->actingAs($admin)->getJson(WA_ADMIN . '/status')->assertOk();
        expect($res->json('data'))->toMatchArray([
            'driver' => 'meta', 'configured' => true, 'phoneNumberId' => '...1234', 'businessAccountId' => '...5678', 'verifyTokenSet' => true, 'appSecretSet' => true,
        ])->and($res->json('data.webhookUrl'))->toEndWith('/api/v1/webhooks/whatsapp')
            ->and($res->getContent())->not->toContain('EAAB-secret-token')->not->toContain('verify-me')->not->toContain('app-secret');

        config(['services.whatsapp.access_token' => null]);
        $this->actingAs($admin)->getJson(WA_ADMIN . '/status')->assertJsonPath('data.configured', false);
    });

    it('lists messages with masked phones, filters, and a summary with the delivery rate', function () {
        [$event] = waEvent();
        $g = waGuest($event, ['name' => 'Ana Pérez']);
        foreach (['sent', 'delivered', 'read', 'failed', 'queued'] as $i => $s) {
            Message::create(['event_id' => $event->id, 'guest_id' => $g->id, 'template_key' => $i % 2 ? 'save_the_date' : 'invitation', 'locale' => 'es',
                'to_phone' => '+13055550123', 'status' => $s, 'queued_at' => now()]);
        }
        $admin = waStaff('support');

        $res = $this->actingAs($admin)->getJson(WA_ADMIN . '/messages')->assertOk()->assertJsonPath('meta.total', 5);
        expect($res->json('data.0.to'))->toBe('+1305***0123')->and($res->json('data.0.eventName'))->toBe('Los XV de Valentina')->and($res->json('data.0.guestName'))->toBe('Ana Pérez')
            ->and($res->getContent())->not->toContain('+13055550123');
        $this->actingAs($admin)->getJson(WA_ADMIN . '/messages?status=failed')->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson(WA_ADMIN . '/messages?template_key=save_the_date')->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson(WA_ADMIN . "/messages?event_id={$event->id}&q=0123")->assertJsonPath('meta.total', 5);
        $this->actingAs($admin)->getJson(WA_ADMIN . '/messages?q=P%C3%A9rez')->assertJsonPath('meta.total', 5);
        $this->actingAs($admin)->getJson(WA_ADMIN . '/messages?q=nadie')->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson(WA_ADMIN . '/messages?from=2999-01-01')->assertJsonPath('meta.total', 0);

        // 4 left the queue; 2 of them were delivered or read.
        $this->actingAs($admin)->getJson(WA_ADMIN . '/summary')->assertJsonPath('data', ['total' => 5, 'queued' => 1, 'sent' => 1, 'delivered' => 1, 'read' => 1, 'failed' => 1, 'deliveryRate' => 0.5]);
        Message::query()->delete();
        $this->actingAs($admin)->getJson(WA_ADMIN . '/summary')->assertJsonPath('data.deliveryRate', null);
    });
});

describe('templates', function () {
    it('edits a template, sends it back to draft, audits it and protects the variables', function () {
        $t = Template::where('key', 'invitation')->where('locale', 'es')->first();
        $t->forceFill(['status' => 'approved'])->save();
        $admin = waStaff();
        $body = str_replace('Con muchísima ilusión', 'Con todo nuestro cariño', $t->body);

        $this->actingAsReauthed($admin)->putJson(WA_ADMIN . "/templates/{$t->id}", ['body' => $body, 'category' => 'utility', 'reason' => 'friendlier wording'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.category', 'utility')->assertJsonPath('data.body', $body);

        $log = AdminAuditLog::where('action', 'whatsapp.template_edit')->first();
        expect($log->reason)->toBe('friendlier wording')->and($log->actor_id)->toBe($admin->id);

        // Removing, renaming or reordering variables is refused.
        foreach ([str_replace('{{4}}', '', $body), str_replace('{{2}}', '{{9}}', $body), str_replace(['{{1}}', '{{2}}'], ['{{2}}', '{{1}}'], $body), str_replace('{{2}}', '{{guest_name}}', $body)] as $bad) {
            $this->actingAsReauthed($admin)->putJson(WA_ADMIN . "/templates/{$t->id}", ['body' => $bad, 'reason' => 'trying to break it'])
                ->assertStatus(422)->assertJsonPath('errors.body.0', 'variables_changed');
        }
        $this->actingAsReauthed($admin)->putJson(WA_ADMIN . '/templates/999999', ['body' => $body, 'reason' => 'no such template'])->assertNotFound();
    });

    it('approves at once on submit with the fake driver', function () {
        $t = Template::where('key', 'padrino_reminder')->where('locale', 'es')->first();
        $this->actingAsReauthed(waStaff())->postJson(WA_ADMIN . "/templates/{$t->id}/submit", ['reason' => 'ready for review'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        expect(AdminAuditLog::where('action', 'whatsapp.template_submit')->count())->toBe(1);
    });

    it('submits to Meta, sends the right payload, and syncs the review result', function () {
        waMeta();
        $t = Template::where('key', 'invitation')->where('locale', 'es')->first();
        Http::fake([
            'graph.facebook.com/*/205566775678/message_templates*' => Http::sequence()
                ->push(['id' => '777001', 'status' => 'PENDING', 'category' => 'MARKETING'], 200)
                ->push(['data' => [['id' => '777001', 'name' => 'quinvite_invitation_es', 'language' => 'es', 'status' => 'APPROVED', 'category' => 'MARKETING'],
                    ['id' => '777002', 'name' => 'quinvite_save_the_date_en', 'language' => 'en_US', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT'],
                    ['id' => '9', 'name' => 'someone_elses_template', 'language' => 'es', 'status' => 'APPROVED']]], 200),
        ]);
        $admin = waStaff();

        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . "/templates/{$t->id}/submit", ['reason' => 'first submission'])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        Http::assertSent(function ($request) {
            $c = $request['components'];

            return $request->url() === 'https://graph.facebook.com/v26.0/205566775678/message_templates'
                && $request->hasHeader('Authorization', 'Bearer EAAB-secret-token')
                && $request['name'] === 'quinvite_invitation_es' && $request['language'] === 'es' && $request['category'] === 'MARKETING'
                && $c[0]['type'] === 'BODY' && $c[0]['text'] === Template::where('key', 'invitation')->where('locale', 'es')->value('body') && str_contains($c[0]['text'], '{{4}}')
                && count($c[0]['example']['body_text'][0]) === 4
                && $c[1] === ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Sí, asistiré'], ['type' => 'QUICK_REPLY', 'text' => 'No podré asistir']]];
        });
        expect($t->refresh()->meta_template_id)->toBe('777001');

        $res = $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/templates/sync', ['reason' => 'pull the review'])->assertOk();
        expect($res->json('data'))->toHaveCount(10);
        expect($t->refresh()->status)->toBe('approved')->and($t->approved_at)->not->toBeNull();
        $std = Template::where('key', 'save_the_date')->where('locale', 'en')->first();
        expect($std->status)->toBe('rejected')->and($std->rejection_reason)->toBe('INVALID_FORMAT');
        expect(AdminAuditLog::where('action', 'whatsapp.template_sync')->count())->toBe(1);
    });

    it('answers whatsapp_unavailable when Meta is not configured or unreachable', function () {
        $t = Template::first();
        $admin = waStaff();
        config(['services.whatsapp.driver' => 'meta']);
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . "/templates/{$t->id}/submit", ['reason' => 'not connected'])->assertStatus(409)->assertJsonPath('code', 'whatsapp_unavailable');
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/templates/sync', ['reason' => 'not connected'])->assertStatus(409)->assertJsonPath('code', 'whatsapp_unavailable');

        waMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'down', 'code' => 2]], 503)]);
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . "/templates/{$t->id}/submit", ['reason' => 'meta is down'])->assertStatus(409)->assertJsonPath('code', 'whatsapp_unavailable');
    });

    it('keeps what Meta said when it refuses a template', function () {
        waMeta();
        $t = Template::first();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_data' => ['details' => 'Body cannot start with a variable.']]], 400)]);
        $this->actingAsReauthed(waStaff())->postJson(WA_ADMIN . "/templates/{$t->id}/submit", ['reason' => 'trying again'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejectionReason', 'Body cannot start with a variable.');
    });
});

describe('test message', function () {
    it('sends a test without event or guest, masks the number everywhere and audits it', function () {
        $admin = waStaff();
        $res = $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/test', ['to' => '305-555-0123', 'template_key' => 'invitation', 'locale' => 'en', 'reason' => 'checking the line'])
            ->assertStatus(202)->assertJsonPath('data.eventId', null)->assertJsonPath('data.guestId', null)->assertJsonPath('data.to', '+1305***0123');
        expect($res->json('data.status'))->toBe('sent');
        expect(FakeGateway::$sent[0]['to'])->toBe('+13055550123')->and(FakeGateway::$sent[0]['template'])->toBe('quinvite_invitation_en');

        $log = AdminAuditLog::where('action', 'whatsapp.test')->first();
        expect($log->reason)->toBe('checking the line')->and(json_encode($log->after))->not->toContain('3055550123');
    });

    it('validates the number and the template state', function () {
        $admin = waStaff();
        $ok = ['to' => '+13055550123', 'template_key' => 'invitation', 'locale' => 'es', 'reason' => 'checking the line'];
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/test', ['to' => 'abc'] + $ok)->assertStatus(422)->assertJsonPath('errors.to.0', 'invalid_phone');
        // padrino_reminder is not sendable until Padrinos exists (draft), even with the fake driver.
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/test', ['template_key' => 'padrino_reminder'] + $ok)->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        config(['services.whatsapp.driver' => 'meta']);
        $this->actingAsReauthed($admin)->postJson(WA_ADMIN . '/test', $ok)->assertStatus(409)->assertJsonPath('code', 'whatsapp_unavailable');
    });
});

it('masks phone numbers in logs when a send fails', function () {
    [$event, $u, $api] = waEvent();
    waGuest($event, ['phone' => '+13055550123']);
    FakeGateway::failNext(new WhatsAppException('131026', 'undeliverable', false));
    Log::spy();

    $this->actingAs($u['owner'])->postJson("$api/send", ['template' => 'invitation', 'audience' => 'all'])->assertStatus(202);

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $ctx['to'] === '+1305***0123')->once();
});
