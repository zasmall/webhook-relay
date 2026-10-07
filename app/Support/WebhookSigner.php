<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the X-Relay-Signature header value:
 *
 *     t=<unix>,v1=<hex HMAC-SHA256 of "{t}.{body}">[,v1=...]
 *
 * One v1 per secret, so receivers keep verifying through a secret rotation.
 */
final class WebhookSigner
{
    /**
     * @param  non-empty-list<string>  $secrets
     */
    public static function header(string $body, array $secrets, int $timestamp): string
    {
        $parts = ["t={$timestamp}"];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.self::signature($body, $secret, $timestamp);
        }

        return implode(',', $parts);
    }

    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
