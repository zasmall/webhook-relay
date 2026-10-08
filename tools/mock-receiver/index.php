<?php

declare(strict_types=1);

/*
 * A mock webhook receiver for demos. Run with:
 *
 *     PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9000 tools/mock-receiver/index.php
 *
 * (`composer dev` starts it.) It verifies X-Relay-Signature with the
 * relay-signature package, then behaves according to the path:
 *
 *     /ok                         200
 *     /flaky?fail=40              500 on 40% of requests, else 200
 *     /slow?ms=2000               waits, then 200 (beyond the relay's timeout it's a timeout)
 *     /down                       503
 *     /gone                       410, which makes the relay disable the endpoint
 *     /throttled?retry_after=30   429 with Retry-After on half the requests
 *
 * The secret defaults to the demo endpoints' shared secret.
 */

use Database\Seeders\DemoSeeder;
use Zasmall\RelaySignature\Exceptions\InvalidSignature;
use Zasmall\RelaySignature\Signer;
use Zasmall\RelaySignature\Verifier;

require __DIR__.'/../../vendor/autoload.php';

function respond(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }

    echo json_encode($body);

    $line = sprintf(
        "[mock-receiver] %s %s → %d %s\n",
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        $status,
        $_SERVER['HTTP_X_RELAY_EVENT_TYPE'] ?? '',
    );
    file_put_contents('php://stderr', $line);

    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(200, ['receiver' => 'mock', 'paths' => ['/ok', '/flaky', '/slow', '/down', '/gone', '/throttled']]);
}

try {
    Verifier::verify(
        $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', Signer::HEADER))] ?? null,
        (string) file_get_contents('php://input'),
        [getenv('MOCK_RECEIVER_SECRET') ?: DemoSeeder::ENDPOINT_SECRET],
        time(),
    );
} catch (InvalidSignature $e) {
    respond(401, ['error' => 'invalid_signature', 'reason' => (new ReflectionClass($e))->getShortName()]);
}

match ($path) {
    '/ok' => respond(200, ['ok' => true]),
    '/flaky' => random_int(1, 100) <= (int) ($query['fail'] ?? 40)
        ? respond(500, ['error' => 'flaky'])
        : respond(200, ['ok' => true]),
    '/slow' => (function () use ($query): never {
        usleep(1000 * min(30_000, (int) ($query['ms'] ?? 2000)));
        respond(200, ['ok' => true]);
    })(),
    '/down' => respond(503, ['error' => 'down for maintenance']),
    '/gone' => respond(410, ['error' => 'this integration has been retired']),
    '/throttled' => random_int(1, 2) === 1
        ? respond(429, ['error' => 'rate_limited'], ['Retry-After' => (string) (int) ($query['retry_after'] ?? 30)])
        : respond(200, ['ok' => true]),
    default => respond(404, ['error' => 'unknown path']),
};
