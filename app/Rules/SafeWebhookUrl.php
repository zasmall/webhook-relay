<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\HostResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects webhook URLs that could be used for SSRF: non-HTTPS URLs, embedded
 * credentials, and hosts on loopback, private, link-local or reserved networks.
 *
 * This is a first line of defence only. DNS can change after an endpoint is
 * saved, so the delivery job must check the resolved address again.
 */
final class SafeWebhookUrl implements ValidationRule
{
    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $parts = parse_url($value);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        $allowedSchemes = config()->boolean('relay.endpoints.require_https') ? ['https'] : ['https', 'http'];

        if (! in_array($scheme, $allowedSchemes, true)) {
            $fail('The :attribute must use '.implode(' or ', $allowedSchemes).'.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('The :attribute may not contain credentials.');

            return;
        }

        if ($host === '' || config()->boolean('relay.endpoints.allow_private_networks')) {
            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $fail('The :attribute may not point to a private or local network.');

            return;
        }

        // An unresolvable host is allowed here; delivery will fail and retry.
        foreach ($this->resolver->resolve($host) as $ip) {
            if (! self::isPublicIp($ip)) {
                $fail('The :attribute may not point to a private or local network.');

                return;
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
