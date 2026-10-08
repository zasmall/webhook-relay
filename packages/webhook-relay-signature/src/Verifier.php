<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature;

use InvalidArgumentException;
use Zasmall\RelaySignature\Exceptions\MalformedSignature;
use Zasmall\RelaySignature\Exceptions\MissingSignature;
use Zasmall\RelaySignature\Exceptions\SignatureMismatch;
use Zasmall\RelaySignature\Exceptions\TimestampOutsideTolerance;

/**
 * Verifies an X-Relay-Signature header against the raw request body.
 *
 * Passes if any v1 signature matches any of the receiver's secrets, so both
 * the relay and the receiver can rotate secrets without downtime. Unknown
 * schemes (v0, v2, ...) are ignored for forward compatibility.
 */
final class Verifier
{
    /** Longer headers are rejected before parsing. */
    private const MAX_HEADER_LENGTH = 4096;

    /**
     * @param  list<string>  $secrets  the receiver's secrets for this endpoint
     * @param  int  $tolerance  seconds a timestamp may differ from $now, either way
     *
     * @throws MissingSignature|MalformedSignature|TimestampOutsideTolerance|SignatureMismatch
     */
    public static function verify(?string $header, string $body, array $secrets, int $now, int $tolerance = 300): void
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));

        if ($secrets === []) {
            throw new InvalidArgumentException('At least one signing secret is required.');
        }

        [$timestamp, $signatures] = self::parse($header);

        // Blocks replays of captured requests; checked before any HMAC work.
        if (abs($now - $timestamp) > $tolerance) {
            throw new TimestampOutsideTolerance;
        }

        foreach ($secrets as $secret) {
            $expected = Signer::signature($body, $secret, $timestamp);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }

        throw new SignatureMismatch;
    }

    /**
     * @return array{int, non-empty-list<string>}
     *
     * @throws MissingSignature|MalformedSignature
     */
    public static function parse(?string $header): array
    {
        $header = trim((string) $header);

        if ($header === '') {
            throw new MissingSignature;
        }

        if (strlen($header) > self::MAX_HEADER_LENGTH) {
            throw new MalformedSignature;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                if ($timestamp !== null || ! ctype_digit($value)) {
                    throw new MalformedSignature;
                }

                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
                    throw new MalformedSignature;
                }

                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new MalformedSignature;
        }

        return [$timestamp, $signatures];
    }
}
