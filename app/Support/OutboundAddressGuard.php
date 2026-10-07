<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\UnsafeDestination;
use App\Rules\SafeWebhookUrl;

/**
 * The SSRF check at send time. Endpoint URLs are validated on save, but DNS
 * can change afterwards (DNS rebinding), so every delivery resolves the host
 * again and pins the connection to the address that was checked.
 */
final class OutboundAddressGuard
{
    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    /**
     * Returns CURLOPT_RESOLVE entries that pin the request to the checked
     * address, or an empty list when no pinning is needed.
     *
     * @return list<string>
     *
     * @throws UnsafeDestination
     */
    public function pin(string $url): array
    {
        if (config()->boolean('relay.endpoints.allow_private_networks')) {
            return [];
        }

        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host === '') {
            throw new UnsafeDestination('The endpoint URL has no host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! SafeWebhookUrl::isPublicIp($host)) {
                throw new UnsafeDestination("Refused to connect to non-public address {$host}.");
            }

            return [];
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new UnsafeDestination("Could not resolve {$host}.");
        }

        foreach ($ips as $ip) {
            if (! SafeWebhookUrl::isPublicIp($ip)) {
                throw new UnsafeDestination("Refused to connect: {$host} resolves to non-public address {$ip}.");
            }
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'http' ? 80 : 443);
        $address = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];

        return ["{$host}:{$port}:{$address}"];
    }
}
