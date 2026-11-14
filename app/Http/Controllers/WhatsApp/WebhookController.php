<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Meta's webhook: verification handshake (GET) and signed events (POST). Nothing is trusted before the signature checks out. */
class WebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        // PHP turns `hub.mode` into `hub_mode` in query strings; accept both spellings.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        $expected = (string) config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && is_string($token) && hash_equals($expected, $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function receive(Request $request): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $given = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        // No secret configured means no webhook: unsigned calls are never accepted.
        if ($secret === '' || ! hash_equals($expected, $given)) {
            return response('', 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (is_array($payload)) {
            ProcessWhatsAppWebhook::dispatch($payload);
        }

        return response('', 200);
    }
}
