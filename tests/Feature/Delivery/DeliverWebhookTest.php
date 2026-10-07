<?php

declare(strict_types=1);

use App\Actions\DisableEndpoint;
use App\Actions\RotateEndpointSecret;
use App\Actions\SendDelivery;
use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    // Retries are dispatched as delayed jobs; capture them instead of running.
    Queue::fake();

    $this->endpoint = Endpoint::factory()->create(['url' => 'https://hooks.example.com/relay']);
    $this->event = Event::factory()->create([
        'type' => 'invoice.paid',
        'payload' => (object) ['invoice_id' => 'inv_1', 'meta' => (object) []],
    ]);
    $this->delivery = Delivery::factory()->create([
        'event_id' => $this->event->id,
        'endpoint_id' => $this->endpoint->id,
    ]);
});

/**
 * Runs the job's handler directly (the queue is faked, so retries it
 * schedules are captured rather than run).
 */
function deliver(Delivery $delivery): void
{
    app()->call([new DeliverWebhook($delivery), 'handle']);
}

it('posts a signed envelope to the endpoint', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $this->freezeSecond();

    deliver($this->delivery);

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        // toEqual: MySQL's JSON column doesn't keep payload key order.
        expect(json_decode($body, true))->toEqual([
            'id' => $this->event->id,
            'type' => 'invoice.paid',
            'created_at' => $this->event->received_at->toIso8601ZuluString(),
            'data' => ['invoice_id' => 'inv_1', 'meta' => []],
        ])
            // Empty objects stay objects on the wire.
            ->and($body)->toContain('"meta":{}')
            ->and($request->url())->toBe('https://hooks.example.com/relay')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Content-Type')[0])->toBe('application/json')
            ->and($request->header('X-Relay-Event-Id')[0])->toBe($this->event->id)
            ->and($request->header('X-Relay-Event-Type')[0])->toBe('invoice.paid')
            ->and($request->header('X-Relay-Delivery-Id')[0])->toBe($this->delivery->id)
            ->and($request->header('User-Agent')[0])->toBe('WebhookRelay/1.0')
            ->and($request->header('X-Relay-Signature')[0])->toStartWith('t='.now()->getTimestamp().',v1=')
            ->and(signatureVerifies($request->header('X-Relay-Signature')[0], $body, $this->endpoint->secret))->toBeTrue()
            ->and(signatureVerifies($request->header('X-Relay-Signature')[0], $body, 'whsec_wrong'))->toBeFalse();

        return true;
    });
});

it('signs with both secrets during a rotation grace window', function () {
    Http::fake(['*' => Http::response('ok')]);

    $old = $this->endpoint->secret;
    app(RotateEndpointSecret::class)->handle($this->endpoint);

    deliver($this->delivery);

    Http::assertSent(function (Request $request) use ($old) {
        $header = $request->header('X-Relay-Signature')[0];

        return substr_count($header, 'v1=') === 2
            && signatureVerifies($header, $request->body(), $old)
            && signatureVerifies($header, $request->body(), $this->endpoint->fresh()->secret);
    });
});

it('marks a 2xx as succeeded and records the attempt', function () {
    Http::fake(['*' => Http::response('{"received":true}', 202)]);
    $this->endpoint->update(['consecutive_failures' => 3]);
    $this->freezeSecond();

    deliver($this->delivery);

    expect($this->delivery->fresh())
        ->status->toBe(DeliveryStatus::Succeeded)
        ->attempts->toBe(1)
        ->last_status_code->toBe(202)
        ->delivered_at->toEqual(now())
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(0);

    $attempt = DeliveryAttempt::sole();

    expect($attempt)
        ->attempt->toBe(1)
        ->status_code->toBe(202)
        ->response_body->toBe('{"received":true}')
        ->error->toBeNull()
        ->duration_ms->toBeGreaterThanOrEqual(0)
        ->and($attempt->request_headers)->toHaveKeys(['X-Relay-Signature', 'X-Relay-Delivery-Id'])
        ->and(json_encode($attempt->request_headers))->not->toContain('inv_1');
});

it('schedules a retry for a failed attempt and counts the failure', function (Closure $response, ?int $status, ?string $error) {
    Http::fake(['*' => $response()]);

    deliver($this->delivery);

    expect($this->delivery->fresh())
        ->status->toBe(DeliveryStatus::Pending)
        ->attempts->toBe(1)
        ->last_status_code->toBe($status)
        ->delivered_at->toBeNull()
        ->next_attempt_at->not->toBeNull()
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(1)
        ->and(DeliveryAttempt::sole()->status_code)->toBe($status);

    if ($error !== null) {
        expect(DeliveryAttempt::sole()->error)->toContain($error);
    }
})->with([
    'server error' => [fn () => Http::response('boom', 500), 500, null],
    'client error' => [fn () => Http::response('nope', 400), 400, null],
    'redirect is not followed' => [fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/']), 302, null],
    'connection failure' => [fn () => Http::failedConnection('Connection timed out'), null, 'Connection timed out'],
]);

it('numbers attempts across tries', function () {
    Http::fakeSequence()->push('boom', 500)->push('ok', 200);

    deliver($this->delivery);
    deliver($this->delivery);

    expect(DeliveryAttempt::orderBy('attempt')->pluck('attempt')->all())->toBe([1, 2])
        ->and($this->delivery->fresh()->attempts)->toBe(2)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Succeeded);
});

it('refuses to send when the host now resolves to a private address', function () {
    Http::fake();
    $this->hosts->pointTo('hooks.example.com', ['10.0.0.8']);

    deliver($this->delivery);

    Http::assertNothingSent();

    expect(DeliveryAttempt::sole())
        ->status_code->toBeNull()
        ->error->toContain('resolves to non-public address 10.0.0.8')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('refuses to send when the host does not resolve', function () {
    Http::fake();
    $this->hosts->pointTo('hooks.example.com', []);

    deliver($this->delivery);

    Http::assertNothingSent();
    expect(DeliveryAttempt::sole()->error)->toContain('Could not resolve hooks.example.com');
});

it('truncates long or invalid response bodies', function () {
    config(['relay.delivery.response_body_limit' => 10]);
    Http::fake(['*' => Http::response("héllo wörld \xFF and more", 500)]);

    deliver($this->delivery);

    $body = DeliveryAttempt::sole()->response_body;

    expect(strlen($body))->toBeLessThanOrEqual(10)
        ->and(mb_check_encoding($body, 'UTF-8'))->toBeTrue();
});

it('skips deliveries that are not pending', function (DeliveryStatus $status) {
    Http::fake();
    $this->delivery->update(['status' => $status]);

    deliver($this->delivery);

    Http::assertNothingSent();
    expect(DeliveryAttempt::count())->toBe(0);
})->with([DeliveryStatus::Delivering, DeliveryStatus::Succeeded, DeliveryStatus::Dead]);

it('leaves deliveries for a disabled endpoint pending', function () {
    Http::fake();
    app(DisableEndpoint::class)->handle($this->endpoint);

    deliver($this->delivery);

    Http::assertNothingSent();
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('marks deliveries for a deleted endpoint dead', function () {
    Http::fake();
    $this->endpoint->delete();

    deliver($this->delivery);

    Http::assertNothingSent();
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Dead);
});

it('does not send when another worker already claimed the delivery', function () {
    Http::fake();

    // Simulates a second worker winning the race after this one loaded the row.
    $stale = Delivery::find($this->delivery->id);
    Delivery::whereKey($this->delivery->id)->update(['status' => DeliveryStatus::Delivering]);

    expect(app(SendDelivery::class)->handle($stale))->toBeNull();
    Http::assertNothingSent();
});

it('puts a delivery stuck in delivering back to pending when the job fails', function () {
    $this->delivery->update(['status' => DeliveryStatus::Delivering]);

    (new DeliverWebhook($this->delivery))->failed(new RuntimeException('worker died'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('is unique per delivery until it starts processing', function () {
    $job = new DeliverWebhook($this->delivery);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe($this->delivery->id)
        ->and($job->queue)->toBe('deliveries')
        // The lock must outlast the longest delayed retry.
        ->and($job->uniqueFor)->toBeGreaterThan(config('relay.retry.max_delay'));
});

it('keeps the attempt log append-only', function () {
    Http::fake(['*' => Http::response('ok')]);
    deliver($this->delivery);
    $attempt = DeliveryAttempt::sole();

    expect(fn () => $attempt->update(['status_code' => 500]))->toThrow(LogicException::class)
        ->and(fn () => $attempt->delete())->toThrow(LogicException::class);
});
