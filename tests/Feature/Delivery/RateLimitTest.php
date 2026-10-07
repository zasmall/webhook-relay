<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

it('rate limits deliveries per endpoint', function () {
    config(['relay.rate_limit.per_minute' => 2]);

    $delivery = Delivery::factory()->create();
    $sameEndpoint = Delivery::factory()->create(['endpoint_id' => $delivery->endpoint_id]);
    $otherEndpoint = Delivery::factory()->create();

    $ran = [];
    $run = function (Delivery $delivery) use (&$ran) {
        $job = new DeliverWebhook($delivery);

        expect($job->middleware())->toHaveCount(1)
            ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class);

        $job->middleware()[0]->handle($job, function () use (&$ran, $delivery) {
            $ran[] = $delivery->id;
        });
    };

    $run($delivery);
    $run($sameEndpoint);
    $run($delivery); // Third for this endpoint this minute: released, not run.
    $run($otherEndpoint);

    expect($ran)->toBe([$delivery->id, $sameEndpoint->id, $otherEndpoint->id]);
});

it('keys the limiter by endpoint', function () {
    $delivery = Delivery::factory()->create();

    $limit = RateLimiter::limiter('deliveries')(new DeliverWebhook($delivery));

    expect($limit->key)->toBe('endpoint:'.$delivery->endpoint_id)
        ->and($limit->maxAttempts)->toBe(config('relay.rate_limit.per_minute'));
});
