<?php

use App\Http\Services\WhatsApp\MetaGateway;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1098', 'services.whatsapp.business_account_id' => '2055',
        'services.whatsapp.access_token' => 'TOKEN', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v', 'services.whatsapp.graph_version' => 'v26.0']);
});

it('sends a template with body parameters and quick-reply payloads in the Graph API shape', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '13055550123', 'wa_id' => '13055550123']], 'messages' => [['id' => 'wamid.HBg1']]], 200)]);

    $id = (new MetaGateway)->sendTemplate('+13055550123', 'quinvite_invitation_es', 'es', ['Ana', 'Los XV', 'sábado', 'https://x/i/a/B'], ['rsvp:7:yes', 'rsvp:7:no']);

    expect($id)->toBe('wamid.HBg1');
    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/1098/messages' && $r->hasHeader('Authorization', 'Bearer TOKEN') && $r->data() === [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '13055550123', 'type' => 'template',
        'template' => ['name' => 'quinvite_invitation_es', 'language' => ['code' => 'es'], 'components' => [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana'], ['type' => 'text', 'text' => 'Los XV'], ['type' => 'text', 'text' => 'sábado'], ['type' => 'text', 'text' => 'https://x/i/a/B']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'rsvp:7:yes']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => 'rsvp:7:no']]],
        ]],
    ]);
});

it('uses en_US for English and omits the button components when there are none', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200)]);
    (new MetaGateway)->sendTemplate('+13055550123', 'quinvite_save_the_date_en', 'en', ['A']);
    Http::assertSent(fn ($r) => $r['template']['language']['code'] === 'en_US' && count($r['template']['components']) === 1);
});

it('sends a session text', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.3']]], 200)]);
    expect((new MetaGateway)->sendText('+13055550123', 'Gracias'))->toBe('wamid.3');
    Http::assertSent(fn ($r) => $r['type'] === 'text' && $r['text'] === ['preview_url' => false, 'body' => 'Gracias'] && $r['to'] === '13055550123');
});

it('classifies errors as retryable or not', function (int $status, array $body, bool $retryable, string $code) {
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
    try {
        (new MetaGateway)->sendText('+13055550123', 'x');
        $this->fail('should throw');
    } catch (WhatsAppException $e) {
        expect($e->retryable)->toBe($retryable)->and($e->errorCode)->toBe($code);
    }
})->with([
    'undeliverable' => [400, ['error' => ['message' => 'm', 'code' => 131026]], false, '131026'],
    'expired token' => [401, ['error' => ['message' => 'm', 'code' => 190]], false, '190'],
    'rate limit' => [429, ['error' => ['message' => 'm', 'code' => 130429]], true, '130429'],
    'pair rate limit' => [400, ['error' => ['message' => 'm', 'code' => 131056]], true, '131056'],
    'server error' => [500, ['error' => ['message' => 'm', 'code' => 2]], true, '2'],
    'no json' => [502, [], true, '502'],
]);

it('treats a network failure as retryable and a response without id as an error', function () {
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timeout')]);
    expect(fn () => (new MetaGateway)->sendText('+13055550123', 'x'))->toThrow(fn (WhatsAppException $e) => $e->retryable === true);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => []], 200)]);
    expect(fn () => (new MetaGateway)->sendText('+13055550123', 'x'))->toThrow(WhatsAppException::class);
});

it('lists templates following pagination and checks the connection', function () {
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'after=XYZ') => Http::response(['data' => [['id' => '2', 'name' => 'b', 'language' => 'en_US', 'status' => 'PENDING', 'rejected_reason' => 'NONE']]], 200),
            str_contains($url, '/2055/message_templates') => Http::response(['data' => [['id' => '1', 'name' => 'a', 'language' => 'es', 'status' => 'PAUSED']], 'paging' => ['next' => 'https://graph.facebook.com/v26.0/2055/message_templates?after=XYZ']], 200),
            default => Http::response(['display_phone_number' => '+1 305'], 200),
        };
    }]);
    $list = (new MetaGateway)->fetchTemplates();
    expect($list)->toHaveCount(2)->and($list[0]['status'])->toBe('paused')->and($list[1])->toMatchArray(['locale' => 'en', 'status' => 'submitted', 'reason' => null]);
    expect((new MetaGateway)->checkConnection())->toBeTrue();
});

it('reports a failed connection check', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'x']], 401)]);
    expect((new MetaGateway)->checkConnection())->toBeFalse();
});

it('normalizes and masks phones', function () {
    expect(Phone::normalize('(305) 555-0123'))->toBe('+13055550123')->and(Phone::normalize('+52 55 1234 5678'))->toBe('+525512345678')
        ->and(Phone::normalize('1-305-555-0123'))->toBe('+13055550123')->and(Phone::normalize('0013055550123'))->toBe('+13055550123')
        ->and(Phone::normalize('555-0123'))->toBeNull()->and(Phone::normalize(''))->toBeNull()->and(Phone::normalize(null))->toBeNull();
    expect(Phone::mask('+13055550123'))->toBe('+1305***0123');
});
