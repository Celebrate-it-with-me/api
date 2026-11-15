<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application (I-04). Set TRUSTED_PROXIES to the load balancer's address(es),
     * comma separated (CIDR allowed), or `*` only when the balancer overwrites X-Forwarded-For. Unset = no proxy is
     * trusted and request()->ip() is the connection's address (the per-IP limits then count the balancer, not people).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));
        $this->proxies = $configured === '' ? null : ($configured === '*' ? '*' : array_map('trim', explode(',', $configured)));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
