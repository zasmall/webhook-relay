<?php

declare(strict_types=1);

use Database\Seeders\DemoSeeder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Zasmall\RelaySignature\Signer;

beforeEach(function () {
    $this->port = 9187;
    $this->receiver = Process::start([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", base_path('tools/mock-receiver/index.php')]);

    // Wait for the built-in server to accept connections.
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; $i++) {
        usleep(50_000);
    }
});

afterEach(function () {
    $this->receiver->stop();
});

function postToMock(string $path, ?string $secret = DemoSeeder::ENDPOINT_SECRET): Response
{
    $body = '{"id":"evt_1","type":"invoice.paid","data":{}}';
    $headers = $secret === null ? [] : [Signer::HEADER => Signer::header($body, [$secret], time())];

    return Http::withHeaders($headers)->withBody($body, 'application/json')->post('http://127.0.0.1:'.test()->port.$path);
}

it('answers each path the way the demo endpoints expect', function () {
    expect(postToMock('/ok')->status())->toBe(200)
        ->and(postToMock('/down')->status())->toBe(503)
        ->and(postToMock('/gone')->status())->toBe(410)
        ->and(postToMock('/flaky?fail=100')->status())->toBe(500)
        ->and(postToMock('/flaky?fail=0')->status())->toBe(200)
        ->and(postToMock('/nowhere')->status())->toBe(404);
});

it('verifies signatures like a real receiver', function () {
    expect(postToMock('/ok', 'whsec_wrong')->status())->toBe(401)
        ->and(postToMock('/ok', null)->json('reason'))->toBe('MissingSignature');
});

it('sends Retry-After when throttling', function () {
    $responses = collect(range(1, 12))->map(fn () => postToMock('/throttled?retry_after=7'));
    $throttled = $responses->first(fn ($response) => $response->status() === 429);

    expect($throttled)->not->toBeNull()
        ->and($throttled->header('Retry-After'))->toBe('7');
});
