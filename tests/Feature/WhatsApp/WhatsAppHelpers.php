<?php

use App\Http\Services\AppServices\EventModules;
use App\Http\Services\WhatsApp\FakeGateway;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Module;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** An event on the paid plan with the whatsapp module on, an owner, a viewer and a stranger. @return array{0: Events, 1: array<string, User>, 2: string} */
function waEvent(string $plan = 'premium', bool $moduleOn = true): array
{
    Module::where('key', 'whatsapp')->update(['is_enabled' => $moduleOn]);
    // A query-builder update skips the model events: tell the module cache (see EventModules).
    app(EventModules::class)->invalidate();
    FakeGateway::reset();
    config(['services.whatsapp.driver' => 'fake']);

    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'published', 'custom_url_slug' => 'xv-wa-' . uniqid(), 'event_name' => 'Los XV de Valentina',
        'start_date' => now()->addDays(10), 'locale' => 'es',
    ]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/whatsapp"];
}

function waGuest(Events $event, array $extra = []): Guest
{
    return Guest::factory()->create(array_merge([
        'event_id' => $event->id, 'parent_id' => null, 'phone' => '+1 305 555 ' . random_int(1000, 9999), 'rsvp_status' => 'pending', 'locale' => 'es',
    ], $extra));
}

function waStaff(string $role = 'superadmin'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function waSign(string $raw, string $secret = 'app-secret'): string
{
    return 'sha256=' . hash_hmac('sha256', $raw, $secret);
}

/** POST a signed webhook body. */
function waWebhook($test, array $payload, ?string $signature = null)
{
    $raw = json_encode($payload);

    return $test->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature ?? waSign($raw),
    ], $raw);
}

function waStatusPayload(string $waId, string $status, array $extra = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
        'statuses' => [array_merge(['id' => $waId, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '13055550123'], $extra)],
    ]]]]]];
}

function waInboundPayload(array $message): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
        'messages' => [array_merge(['id' => 'wamid.in.' . uniqid(), 'timestamp' => (string) now()->timestamp], $message)],
    ]]]]]];
}
