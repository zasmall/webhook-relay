<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Source;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('delivers an ingested event to every subscriber, signed', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('ok')]);

    $this->travelTo(now()->subMinute());
    $billing = Endpoint::factory()->create(['url' => 'https://billing.example.com/hook', 'event_types' => ['invoice.*']]);
    $crm = Endpoint::factory()->create(['url' => 'https://crm.example.com/hook', 'event_types' => ['customer.*']]);
    $this->travelBack();

    Sanctum::actingAs(Source::factory()->create());

    // The queue runs synchronously in tests, so fan-out and delivery happen here.
    $this->postJson('/api/events', [
        'type' => 'invoice.paid',
        'payload' => ['invoice_id' => 'inv_9'],
    ], ['Idempotency-Key' => 'e2e'])->assertAccepted();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://billing.example.com/hook'
        && signatureVerifies($request->header('X-Relay-Signature')[0], $request->body(), $billing->secret)
        && json_decode($request->body(), true)['data'] === ['invoice_id' => 'inv_9']);

    expect(Delivery::sole())
        ->endpoint_id->toBe($billing->id)
        ->status->toBe(DeliveryStatus::Succeeded)
        ->and($crm->deliveries()->count())->toBe(0);
});
