<?php

namespace App\Http;

/**
 * Which proxies may tell the app the visitor's real IP (X-Forwarded-For), from
 * TRUSTED_PROXIES (bootstrap/app.php).
 *
 * On Render a request goes visitor → Cloudflare → Render's load balancer → app.
 * Trusting only the load balancer ("*" in Laravel) gives a Cloudflare server's
 * address as the visitor's IP (wrong country in the login history, one shared
 * sign-in rate limit for everyone behind that server). So "*" also trusts
 * Cloudflare's own ranges: only Cloudflare can connect from them.
 */
final class TrustedProxies
{
    /**
     * Cloudflare's published ranges (https://www.cloudflare.com/ips/); they
     * rarely change. Check them again if visitor IPs show 104.x / 172.6x-7x
     * / 162.158.x addresses in the login history.
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The proxies to trust for a TRUSTED_PROXIES value: "*" = the load balancer
     * calling the app plus Cloudflare; otherwise the comma-separated list;
     * empty = none (null).
     *
     * @return list<string>|null
     */
    public static function from(?string $setting): ?array
    {
        $setting = trim((string) $setting);

        return match ($setting) {
            '' => null,
            '*' => ['REMOTE_ADDR', ...self::CLOUDFLARE],
            default => array_values(array_filter(array_map(trim(...), explode(',', $setting)), fn ($ip) => $ip !== '')),
        };
    }
}
