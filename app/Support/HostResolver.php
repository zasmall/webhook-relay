<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves a hostname to its IP addresses. Wrapped in a class so tests can
 * swap it out instead of depending on real DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
