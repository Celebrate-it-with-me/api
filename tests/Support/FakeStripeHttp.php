<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/** A stand-in for Stripe's HTTP API (no network, no account): records every call and answers like the real one would. */
class FakeStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, path: string, headers: array, params: array}> */
    public array $calls = [];

    /** @var array<string, array> sessions by id */
    public array $sessions = [];

    public bool $failNext = false;

    /** @var list<array> refunds and disputes the "account" holds, by payment intent */
    public array $refunds = [];

    public array $disputes = [];

    private int $n = 0;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->calls[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'params' => $params];

        if ($this->failNext) {
            $this->failNext = false;

            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'boom']]), 400, []];
        }

        if ($method === 'post' && $path === '/v1/checkout/sessions') {
            $id = 'cs_test_' . (++$this->n);
            $this->sessions[$id] = [
                'id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid',
                'url' => "https://checkout.stripe.com/c/pay/{$id}", 'expires_at' => $params['expires_at'],
                'payment_intent' => null, 'amount_total' => $params['line_items'][0]['price_data']['unit_amount'], 'currency' => 'usd',
            ];

            return [json_encode($this->sessions[$id]), 200, []];
        }
        if ($method === 'get' && preg_match('#^/v1/checkout/sessions/([^/]+)$#', $path, $m)) {
            return [json_encode($this->sessions[$m[1]]), 200, []];
        }
        if ($method === 'post' && preg_match('#^/v1/checkout/sessions/([^/]+)/expire$#', $path, $m)) {
            if ($this->sessions[$m[1]]['status'] !== 'open') {
                return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Only open sessions can be expired.']]), 400, []];
            }
            $this->sessions[$m[1]]['status'] = 'expired';

            return [json_encode($this->sessions[$m[1]]), 200, []];
        }
        if ($method === 'get' && $path === '/v1/refunds') {
            $rows = array_values(array_filter($this->refunds, fn ($r) => $r['payment_intent'] === ($params['payment_intent'] ?? null)));

            return [json_encode(['object' => 'list', 'data' => $rows, 'has_more' => false, 'url' => $path]), 200, []];
        }
        if ($method === 'get' && $path === '/v1/disputes') {
            $rows = array_values(array_filter($this->disputes, fn ($d) => $d['payment_intent'] === ($params['payment_intent'] ?? null)));

            return [json_encode(['object' => 'list', 'data' => $rows, 'has_more' => false, 'url' => $path]), 200, []];
        }
        if ($method === 'post' && $path === '/v1/refunds') {
            return [json_encode(['id' => 're_test_' . (++$this->n), 'object' => 'refund', 'status' => 'succeeded']), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No route {$method} {$path}"]]), 404, []];
    }

    /** The customer paid: the session is complete. */
    public function pay(string $sessionId, string $paymentIntent = 'pi_test_1'): void
    {
        $this->sessions[$sessionId]['status'] = 'complete';
        $this->sessions[$sessionId]['payment_status'] = 'paid';
        $this->sessions[$sessionId]['payment_intent'] = $paymentIntent;
    }

    /** @return list<array> the calls to a path (and method) */
    public function callsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method && preg_match($pathPattern, $c['path'])));
    }
}
