<?php

declare(strict_types=1);

use App\Actions\SendDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\EndpointHealth;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\User;
use App\Queries\DashboardSummaryQuery;
use App\Queries\EndpointStatsQuery;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoVolume;
use Illuminate\Support\Facades\Hash;
use Zasmall\RelaySignature\Verifier;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('creates a demo operator who can log in', function () {
    $user = User::sole();

    expect($user->email)->toBe(DemoSeeder::EMAIL)
        ->and(Hash::check(DemoSeeder::PASSWORD, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('covers every endpoint health state', function () {
    $endpoints = Endpoint::all();
    $stats = app(EndpointStatsQuery::class)->forEndpoints(array_values($endpoints->modelKeys()));

    $health = $endpoints
        ->map(fn (Endpoint $e) => EndpointHealth::for($e->is_active, $e->consecutive_failures, $stats[$e->id])->value)
        ->unique()
        ->values();

    expect($health->all())->toEqualCanonicalizing(array_column(EndpointHealth::cases(), 'value'));
});

it('builds consistent delivery histories', function () {
    expect(Delivery::where('status', DeliveryStatus::Succeeded)->count())->toBeGreaterThan(100)
        ->and(Delivery::where('status', DeliveryStatus::Dead)->count())->toBeGreaterThan(0)
        ->and(Delivery::where('replay_count', 1)->count())->toBe(1);

    // Every delivery's attempt numbers run 1..n with no gaps.
    Delivery::with('attemptLog')->get()
        ->filter(fn (Delivery $delivery) => $delivery->attemptLog->isNotEmpty())
        ->each(fn (Delivery $delivery) => expect($delivery->attemptLog->pluck('attempt')->all())
            ->toBe(range(1, $delivery->attemptLog->count())));

    // Disabled endpoints get no deliveries for events after they were disabled.
    Endpoint::where('is_active', false)->get()->each(function (Endpoint $endpoint) {
        expect($endpoint->deliveries()->where('created_at', '>', $endpoint->disabled_at)->count())->toBe(0);
    });
});

it('signs recorded requests with the endpoint secret', function () {
    $attempt = DeliveryAttempt::with('delivery.event')->first();
    $body = SendDelivery::body($attempt->delivery->event);
    $header = $attempt->request_headers['X-Relay-Signature'];
    [$timestamp] = Verifier::parse($header);

    Verifier::verify($header, $body, [DemoSeeder::ENDPOINT_SECRET], $timestamp);
})->throwsNoExceptions();

it('adds bulk volume outside the 24-hour window', function () {
    $before = app(DashboardSummaryQuery::class)->get();
    $deliveries = Delivery::count();

    app(DemoVolume::class)->add(50);

    expect(Delivery::count())->toBe($deliveries + 50)
        ->and(app(DashboardSummaryQuery::class)->get())->toBe($before);
});
