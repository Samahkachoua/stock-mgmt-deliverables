<?php

use App\Http\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

afterEach(function () {
    TrustProxies::flushState();
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

/**
 * The visitor IP the app sees for a request arriving from $remote (the hop
 * calling the app) with this X-Forwarded-For, using TRUSTED_PROXIES=$setting.
 */
function clientIp(?string $setting, string $remote, string $forwardedFor): string
{
    $request = Request::create('/login', 'GET', server: ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $forwardedFor]);

    if (($proxies = TrustedProxies::from($setting)) !== null) {
        TrustProxies::at($proxies);
    }

    return (new TrustProxies)->handle($request, fn (Request $request) => response($request->ip()))->getContent();
}

it('sees the visitor behind Cloudflare and the Render load balancer', function () {
    // Visitor in Lebanon → Cloudflare server (Germany) → Render load balancer → app.
    expect(clientIp('*', '10.214.3.7', '94.187.0.1, 172.68.192.185'))->toBe('94.187.0.1');
});

it('sees the visitor behind the load balancer alone', function () {
    expect(clientIp('*', '10.214.3.7', '94.187.0.1'))->toBe('94.187.0.1');
});

it('does not let a visitor fake their address', function () {
    // The visitor sends their own X-Forwarded-For; Cloudflare appends the real address.
    expect(clientIp('*', '10.214.3.7', '8.8.8.8, 94.187.0.1, 172.68.192.185'))->toBe('94.187.0.1');
});

it('trusts no forwarded address when TRUSTED_PROXIES is empty', function () {
    expect(clientIp('', '94.187.0.1', '8.8.8.8'))->toBe('94.187.0.1')
        ->and(TrustedProxies::from(null))->toBeNull();
});

it('keeps an explicit list as it is', function () {
    expect(TrustedProxies::from(' 10.0.0.1 , 10.0.0.2,'))->toBe(['10.0.0.1', '10.0.0.2']);
});
