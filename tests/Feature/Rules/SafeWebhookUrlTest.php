<?php

declare(strict_types=1);

use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Facades\Validator;

function urlPasses(string $url): bool
{
    return Validator::make(['url' => $url], ['url' => [app(SafeWebhookUrl::class)]])->passes();
}

it('accepts public https URLs', function () {
    expect(urlPasses('https://hooks.example.com/relay'))->toBeTrue()
        ->and(urlPasses('https://8.8.8.8/hook'))->toBeTrue();
});

it('rejects unsafe URLs', function (string $url) {
    expect(urlPasses($url))->toBeFalse();
})->with([
    'http' => 'http://hooks.example.com',
    'ftp' => 'ftp://hooks.example.com',
    'credentials' => 'https://user:pass@hooks.example.com',
    'localhost' => 'https://localhost/hook',
    'localhost subdomain' => 'https://app.localhost/hook',
    'loopback' => 'https://127.0.0.1/hook',
    'private 10/8' => 'https://10.0.0.5/hook',
    'private 192.168/16' => 'https://192.168.1.10/hook',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'ipv6 loopback' => 'https://[::1]/hook',
    'ipv6 unique local' => 'https://[fd00::1]/hook',
]);

it('rejects a hostname that resolves to a private address', function () {
    $this->hosts->pointTo('sneaky.example.com', ['93.184.215.14', '10.0.0.1']);

    expect(urlPasses('https://sneaky.example.com/hook'))->toBeFalse();
});

it('allows a hostname that does not resolve yet', function () {
    $this->hosts->pointTo('not-live-yet.example.com', []);

    expect(urlPasses('https://not-live-yet.example.com/hook'))->toBeTrue();
});

it('allows http and private networks when configured for local development', function () {
    config([
        'relay.endpoints.require_https' => false,
        'relay.endpoints.allow_private_networks' => true,
    ]);

    expect(urlPasses('http://localhost:8001/hook'))->toBeTrue()
        ->and(urlPasses('http://127.0.0.1/hook'))->toBeTrue();
});
