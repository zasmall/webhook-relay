<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Source;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function ingest(array $body, ?string $key = 'key-1'): TestResponse
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return test()->postJson('/api/events', $body, $headers);
}

function validBody(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice.paid',
        'payload' => ['invoice_id' => 'inv_123', 'amount' => 4200],
    ], $overrides);
}

beforeEach(function () {
    $this->source = Source::factory()->create();
});

describe('authentication', function () {
    it('rejects requests without a token', function () {
        ingest(validBody())->assertUnauthorized();
    });

    it('rejects an invalid token', function () {
        $this->withToken('1|not-a-real-token');

        ingest(validBody())->assertUnauthorized();
    });

    it('accepts a token issued to the source', function () {
        $token = $this->source->createToken('ingest')->plainTextToken;

        $this->withToken($token);

        ingest(validBody())->assertAccepted();

        expect(Event::sole()->source_id)->toBe($this->source->id);
    });
});

describe('ingest', function () {
    beforeEach(fn () => Sanctum::actingAs($this->source));

    it('stores a new event and returns 202', function () {
        $response = ingest(validBody(), key: 'order-42');

        $event = Event::sole();

        $response->assertAccepted()
            ->assertExactJson(['data' => [
                'id' => $event->id,
                'type' => 'invoice.paid',
                'payload' => ['invoice_id' => 'inv_123', 'amount' => 4200],
                'idempotency_key' => 'order-42',
                'received_at' => $event->received_at->toIso8601ZuluString(),
            ]]);

        expect($event)
            ->source_id->toBe($this->source->id)
            ->type->toBe('invoice.paid');
    });

    it('keeps empty nested objects as objects', function () {
        $raw = '{"type":"invoice.paid","payload":{"meta":{},"items":[]}}';

        $this->call('POST', '/api/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'key-1',
        ], $raw)->assertAccepted();

        expect(json_encode(Event::sole()->payload))->toBe('{"meta":{},"items":[]}');
    });

    it('returns the original event with 200 for a duplicate key', function () {
        $original = ingest(validBody(), key: 'dup')->assertAccepted()->json('data');

        $duplicate = ingest(validBody(), key: 'dup')->assertOk()->json('data');

        // toEqual, not toBe: MySQL's JSON column doesn't preserve key order.
        expect($duplicate)->toEqual($original)
            ->and(Event::count())->toBe(1);
    });

    it('ignores a different body sent with an existing key', function () {
        $original = ingest(validBody(), key: 'dup')->json('data');

        $duplicate = ingest(validBody(['type' => 'invoice.voided', 'payload' => ['x' => 1]]), key: 'dup')
            ->assertOk()
            ->json('data');

        expect($duplicate)->toEqual($original)
            ->and(Event::sole()->type)->toBe('invoice.paid');
    });

    it('scopes idempotency keys to the source', function () {
        Event::factory()->create(['idempotency_key' => 'shared']);

        ingest(validBody(), key: 'shared')->assertAccepted();

        expect(Event::count())->toBe(2);
    });
});

describe('validation', function () {
    beforeEach(fn () => Sanctum::actingAs($this->source));

    it('requires an Idempotency-Key header', function () {
        ingest(validBody(), key: null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'Idempotency-Key header']);
    });

    it('limits the Idempotency-Key length', function () {
        ingest(validBody(), key: str_repeat('k', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    });

    it('rejects invalid event types', function (mixed $type) {
        ingest(validBody(['type' => $type]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    })->with([
        'missing' => null,
        'uppercase' => 'Invoice.Paid',
        'spaces' => 'invoice paid',
        'leading dot' => '.invoice',
        'trailing dot' => 'invoice.',
        'wildcard' => 'invoice.*',
        'too long' => str_repeat('a', 256),
    ]);

    it('requires the payload to be a non-empty JSON object', function (mixed $payload) {
        ingest(validBody(['payload' => $payload]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload');
    })->with([
        'missing' => null,
        'empty object' => [[]],
        'list' => [[1, 2, 3]],
        'string' => 'hello',
        'number' => 42,
    ]);

    it('stores nothing when validation fails', function () {
        ingest(validBody(['type' => 'Bad Type']));

        expect(Event::count())->toBe(0);
    });
});

describe('payload size limit', function () {
    beforeEach(fn () => Sanctum::actingAs($this->source));

    it('rejects bodies over the limit with 413', function () {
        config(['relay.ingest.max_payload_bytes' => 1024]);

        ingest(validBody(['payload' => ['blob' => str_repeat('x', 1024)]]))
            ->assertStatus(413)
            ->assertJsonPath('message', 'The request body may not be larger than 1024 bytes.');

        expect(Event::count())->toBe(0);
    });

    it('accepts bodies at the limit', function () {
        $body = validBody(['payload' => ['blob' => '']]);
        $padding = 1024 - strlen((string) json_encode($body));
        $body['payload']['blob'] = str_repeat('x', $padding);

        config(['relay.ingest.max_payload_bytes' => 1024]);

        expect(strlen((string) json_encode($body)))->toBe(1024);

        ingest($body)->assertAccepted();
    });
});
