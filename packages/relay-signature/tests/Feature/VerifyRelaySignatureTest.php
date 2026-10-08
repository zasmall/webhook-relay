<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Zasmall\RelaySignature\Signer;

beforeEach(function () {
    config(['relay-signature.secrets' => ['whsec_current']]);

    Route::post('/hook', fn () => response()->json(['ok' => true]))->middleware('relay.signature');
    Route::post('/billing-hook', fn () => response()->json(['ok' => true]))
        ->middleware('relay.signature:services.billing.relay_secrets');
});

function deliver(string $uri, string $body, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_X_RELAY_SIGNATURE'] = $signature;
    }

    return test()->call('POST', $uri, [], [], [], $server, $body);
}

it('lets a correctly signed request through', function () {
    $body = '{"id":"evt_1","type":"invoice.paid","data":{}}';

    deliver('/hook', $body, Signer::header($body, ['whsec_current'], time()))
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('verifies the raw body, byte for byte', function () {
    $signed = '{"id":"evt_1","data":{"a":1}}';
    $sent = '{"id":"evt_1", "data":{"a":1}}'; // same JSON, different bytes

    deliver('/hook', $sent, Signer::header($signed, ['whsec_current'], time()))->assertUnauthorized();
});

it('answers 400 for a missing or malformed header', function (?string $header) {
    deliver('/hook', '{}', $header)
        ->assertBadRequest()
        ->assertExactJson(['message' => 'Invalid webhook signature.']);
})->with([null, 't=nope']);

it('answers 401 for a wrong secret or a stale timestamp', function (array $secrets, int $at) {
    deliver('/hook', '{}', Signer::header('{}', $secrets, $at))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid webhook signature.']);
})->with([
    'wrong secret' => [['whsec_other'], time()],
    'stale' => [['whsec_current'], time() - 301],
]);

it('uses the configured tolerance', function () {
    config(['relay-signature.tolerance' => 1000]);

    deliver('/hook', '{}', Signer::header('{}', ['whsec_current'], time() - 900))->assertOk();
});

it('accepts any configured secret, for receiver-side rotation', function () {
    config(['relay-signature.secrets' => ['whsec_next', 'whsec_current']]);

    deliver('/hook', '{}', Signer::header('{}', ['whsec_current'], time()))->assertOk();
});

it('reads secrets from a per-route config key', function () {
    config(['services.billing.relay_secrets' => 'whsec_billing']);

    deliver('/billing-hook', '{}', Signer::header('{}', ['whsec_billing'], time()))->assertOk();
    deliver('/billing-hook', '{}', Signer::header('{}', ['whsec_current'], time()))->assertUnauthorized();
});

it('fails loudly when no secret is configured', function () {
    config(['relay-signature.secrets' => []]);
    $this->withoutExceptionHandling();

    deliver('/hook', '{}', Signer::header('{}', ['whsec_current'], time()));
})->throws(InvalidArgumentException::class);

it('publishes its config', function () {
    expect(config('relay-signature.tolerance'))->toBe(300)
        ->and(app('router')->getMiddleware())->toHaveKey('relay.signature');
});
