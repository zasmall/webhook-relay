<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Support\HostResolver;

/**
 * Resolves every hostname to a public IP unless told otherwise, so tests never
 * touch real DNS.
 */
final class FakeHostResolver extends HostResolver
{
    /** @var array<string, list<string>> */
    private array $hosts = [];

    /**
     * @param  list<string>  $ips
     */
    public function pointTo(string $host, array $ips): self
    {
        $this->hosts[strtolower($host)] = $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[strtolower($host)] ?? ['93.184.215.14'];
    }
}
