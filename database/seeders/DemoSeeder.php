<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\SendDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\EndpointDisabledReason;
use App\Models\Endpoint;
use App\Models\Event;
use App\Models\Source;
use App\Models\User;
use App\Support\EventTypePattern;
use App\Support\RetrySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Zasmall\RelaySignature\Signer;

/**
 * A week of believable relay history: endpoints in every health state, with
 * attempt timelines produced by the real retry schedule, attempt limit,
 * circuit breaker and 410 handling. Deterministic (seeded), and relative to
 * now so the 24-hour dashboard is populated.
 *
 * Endpoints point at the mock receiver (tools/mock-receiver, port 9000),
 * which verifies signatures with ENDPOINT_SECRET.
 */
final class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@example.com';

    public const PASSWORD = 'password';

    /** Shared by every demo endpoint so the mock receiver can verify them. */
    public const ENDPOINT_SECRET = 'whsec_demo-only-mock-receiver-secret-not-for-production';

    public const RECEIVER = 'http://127.0.0.1:9000';

    private const DAYS = 7;

    private const EVENTS_PER_DAY = 45;

    private Randomizer $random;

    private RetrySchedule $schedule;

    private CarbonImmutable $now;

    /**
     * How each demo endpoint's receiver behaves.
     *
     * @var list<array{description: string, path: string, types: list<string>, profile: string}>
     */
    private const ENDPOINTS = [
        ['description' => 'Accounting sync', 'path' => '/ok', 'types' => ['invoice.*'], 'profile' => 'ok'],
        ['description' => 'Analytics warehouse', 'path' => '/flaky?fail=10', 'types' => ['*'], 'profile' => 'flaky10'],
        ['description' => 'Fulfilment service', 'path' => '/slow?ms=300', 'types' => ['order.*'], 'profile' => 'slow'],
        ['description' => 'Legacy CRM', 'path' => '/flaky?fail=70', 'types' => ['customer.*'], 'profile' => 'flaky70'],
        ['description' => 'Old partner integration', 'path' => '/gone', 'types' => ['invoice.paid'], 'profile' => 'gone'],
        ['description' => 'Slack notifier', 'path' => '/throttled?retry_after=30', 'types' => ['order.created'], 'profile' => 'throttled'],
        ['description' => 'Status page', 'path' => '/down', 'types' => ['invoice.voided', 'order.shipped'], 'profile' => 'down'],
        ['description' => 'Newsletter tool', 'path' => '/ok', 'types' => ['newsletter.*'], 'profile' => 'ok'],
    ];

    private const TYPES = [
        'invoice.created' => 6, 'invoice.paid' => 5, 'invoice.voided' => 1,
        'order.created' => 6, 'order.shipped' => 4,
        'customer.created' => 2, 'customer.updated' => 3,
    ];

    public function run(): void
    {
        $this->random = new Randomizer(new Mt19937(2026));
        $this->schedule = new RetrySchedule(
            config()->integer('relay.retry.base_delay'),
            config()->integer('relay.retry.max_delay'),
            new Randomizer(new Mt19937(42)),
        );
        $this->now = CarbonImmutable::now()->startOfSecond();
        $start = $this->now->subDays(self::DAYS);

        User::factory()->create([
            'name' => 'Demo Operator',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'email_verified_at' => $start,
        ]);

        $sources = collect(['billing-app', 'storefront', 'crm'])
            ->map(fn (string $name) => Source::create(['name' => $name]));

        $endpoints = collect(self::ENDPOINTS)->map(function (array $spec) use ($start): array {
            $endpoint = new Endpoint([
                'url' => self::RECEIVER.$spec['path'],
                'description' => $spec['description'],
                'event_types' => $spec['types'],
            ]);
            $endpoint->secret = self::ENDPOINT_SECRET;
            $endpoint->created_at = $start->subDay();
            $endpoint->save();

            return ['model' => $endpoint, 'profile' => $spec['profile']];
        });

        $events = $this->events(array_values($sources->all()), $start);

        foreach ($endpoints as ['model' => $endpoint, 'profile' => $profile]) {
            $this->simulate($endpoint, $profile, $events);
        }

        $this->replayOne();
    }

    /**
     * @param  list<Source>  $sources
     * @return list<Event>
     */
    private function events(array $sources, CarbonImmutable $start): array
    {
        $rows = [];
        $total = self::DAYS * self::EVENTS_PER_DAY;
        $span = $this->now->subMinutes(2)->getTimestamp() - $start->getTimestamp();

        for ($i = 0; $i < $total; $i++) {
            // Denser towards the present, so the last 24 hours are busy.
            $offset = (int) round($span * sqrt($this->random->getFloat(0, 1)));
            $receivedAt = $start->addSeconds($offset);
            $type = $this->weightedType();

            $rows[] = [
                'id' => strtolower((string) Str::ulid($receivedAt)),
                'source_id' => $sources[$this->random->getInt(0, count($sources) - 1)]->id,
                'type' => $type,
                'payload' => json_encode($this->payload($type, $i), JSON_UNESCAPED_SLASHES),
                'idempotency_key' => "demo-{$i}",
                'received_at' => $receivedAt,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['received_at'] <=> $b['received_at']);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('events')->insert($chunk);
        }

        return array_values(Event::orderBy('received_at')->get()->all());
    }

    /**
     * Plays each matching event against the endpoint's receiver profile, in
     * time order, applying the relay's real rules.
     *
     * @param  list<Event>  $events
     */
    private function simulate(Endpoint $endpoint, string $profile, array $events): void
    {
        $maxAttempts = config()->integer('relay.retry.max_attempts');
        $breakerAt = config()->integer('relay.circuit_breaker.failure_threshold');

        $deliveries = [];
        $attempts = [];
        $failures = 0;
        $disabledAt = null;
        $disabledReason = null;

        foreach ($events as $event) {
            if ($disabledAt !== null && $event->received_at->greaterThan($disabledAt)) {
                break; // A disabled endpoint gets no new deliveries.
            }

            if (! $this->subscribed($endpoint, $event->type)) {
                continue;
            }

            $deliveryId = strtolower((string) Str::ulid($event->received_at));
            $body = SendDelivery::body($event);
            $at = $event->received_at->addSeconds($this->random->getInt(1, 3));
            $status = DeliveryStatus::Pending;
            $run = 0;
            $lastCode = null;
            $deliveredAt = null;
            $nextAttemptAt = null;

            while (true) {
                if ($at->greaterThan($this->now) || ($disabledAt !== null && $at->greaterThan($disabledAt))) {
                    $nextAttemptAt = $at; // Still waiting (or parked behind a disabled endpoint).
                    break;
                }

                $run++;
                [$code, $error, $responseBody, $retryAfter] = $this->respond($profile);
                $lastCode = $code;
                $attempts[] = $this->attemptRow($deliveryId, $run, $endpoint, $event, $body, $at, $code, $error, $responseBody, $profile);

                if ($code !== null && $code >= 200 && $code < 300) {
                    $status = DeliveryStatus::Succeeded;
                    $deliveredAt = $at;
                    $failures = 0;
                    break;
                }

                if ($code === 410) {
                    $status = DeliveryStatus::Dead;
                    $disabledAt = $at;
                    $disabledReason = EndpointDisabledReason::Gone;
                    break;
                }

                if ($code !== 429) {
                    $failures++;

                    if ($failures >= $breakerAt && $disabledAt === null) {
                        $disabledAt = $at;
                        $disabledReason = EndpointDisabledReason::CircuitBreaker;
                    }
                }

                if ($run >= $maxAttempts) {
                    $status = DeliveryStatus::Dead;
                    break;
                }

                $at = $at->addSeconds($retryAfter ?? $this->schedule->delayFor($run));
            }

            $deliveries[] = [
                'id' => $deliveryId,
                'event_id' => $event->id,
                'endpoint_id' => $endpoint->id,
                'status' => $status->value,
                'attempts' => $run,
                'next_attempt_at' => $status === DeliveryStatus::Pending ? $nextAttemptAt : null,
                'last_status_code' => $lastCode,
                'delivered_at' => $deliveredAt,
                'replay_count' => 0,
                'created_at' => $event->received_at,
                'updated_at' => $deliveredAt ?? $at,
            ];
        }

        foreach (array_chunk($deliveries, 500) as $chunk) {
            DB::table('deliveries')->insert($chunk);
        }

        foreach (array_chunk($attempts, 500) as $chunk) {
            DB::table('delivery_attempts')->insert($chunk);
        }

        $endpoint->forceFill([
            'consecutive_failures' => min($failures, $breakerAt),
            'is_active' => $disabledAt === null,
            'disabled_at' => $disabledAt,
            'disabled_reason' => $disabledReason,
        ])->save();
    }

    /**
     * @return array{?int, ?string, ?string, ?int} status, error, body, Retry-After
     */
    private function respond(string $profile): array
    {
        $roll = $this->random->getInt(1, 100);

        return match ($profile) {
            'ok', 'slow' => [200, null, '{"ok":true}', null],
            'flaky10' => $roll <= 10 ? [500, null, 'Internal Server Error', null] : [200, null, '{"ok":true}', null],
            'flaky70' => match (true) {
                $roll <= 55 => [500, null, 'Internal Server Error', null],
                $roll <= 70 => [null, 'cURL error 28: Operation timed out after 10001 milliseconds', null, null],
                default => [200, null, '{"ok":true}', null],
            },
            'gone' => [410, null, '{"error":"This integration has been retired"}', null],
            'throttled' => $roll <= 40 ? [429, null, '{"error":"rate_limited"}', 30] : [200, null, '{"ok":true}', null],
            'down' => [503, null, 'Service Unavailable', null],
            default => [200, null, '{"ok":true}', null],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptRow(string $deliveryId, int $n, Endpoint $endpoint, Event $event, string $body, CarbonImmutable $at, ?int $code, ?string $error, ?string $responseBody, string $profile): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => config()->string('relay.delivery.user_agent'),
            'X-Relay-Event-Id' => $event->id,
            'X-Relay-Event-Type' => $event->type,
            'X-Relay-Delivery-Id' => $deliveryId,
            Signer::HEADER => Signer::header($body, [$endpoint->secret], $at->getTimestamp()),
        ];

        $duration = match (true) {
            $error !== null => 10_001,
            $profile === 'slow' => $this->random->getInt(280, 420),
            default => $this->random->getInt(18, 140),
        };

        return [
            'delivery_id' => $deliveryId,
            'attempt' => $n,
            'request_headers' => json_encode($headers, JSON_UNESCAPED_SLASHES),
            'status_code' => $code,
            'response_body' => $responseBody,
            'error' => $error,
            'duration_ms' => $duration,
            'created_at' => $at,
        ];
    }

    /**
     * One Legacy CRM delivery that died, was replayed and then got through,
     * so the attempt timeline shows a replay.
     */
    private function replayOne(): void
    {
        $delivery = DB::table('deliveries')
            ->join('endpoints', 'endpoints.id', '=', 'deliveries.endpoint_id')
            ->where('endpoints.description', 'Legacy CRM')
            ->where('deliveries.status', DeliveryStatus::Dead->value)
            ->orderBy('deliveries.id')
            ->select('deliveries.*')
            ->first();

        if ($delivery === null) {
            return;
        }

        $replayedAt = CarbonImmutable::parse($delivery->updated_at)->addHours(2);
        $headers = json_decode((string) DB::table('delivery_attempts')->where('delivery_id', $delivery->id)->value('request_headers'), true);
        $last = (int) DB::table('delivery_attempts')->where('delivery_id', $delivery->id)->max('attempt');

        DB::table('delivery_attempts')->insert([
            'delivery_id' => $delivery->id,
            'attempt' => $last + 1,
            'request_headers' => json_encode($headers, JSON_UNESCAPED_SLASHES),
            'status_code' => 200,
            'response_body' => '{"ok":true}',
            'error' => null,
            'duration_ms' => 64,
            'created_at' => $replayedAt,
        ]);

        DB::table('deliveries')->where('id', $delivery->id)->update([
            'status' => DeliveryStatus::Succeeded->value,
            'attempts' => 1,
            'last_status_code' => 200,
            'delivered_at' => $replayedAt,
            'replay_count' => 1,
            'last_replayed_at' => $replayedAt,
            'updated_at' => $replayedAt,
        ]);
    }

    private function subscribed(Endpoint $endpoint, string $type): bool
    {
        foreach ($endpoint->event_types as $pattern) {
            if (EventTypePattern::matches($pattern, $type)) {
                return true;
            }
        }

        return false;
    }

    private function weightedType(): string
    {
        $roll = $this->random->getInt(1, array_sum(self::TYPES));

        foreach (self::TYPES as $type => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $type;
            }
        }

        return array_key_first(self::TYPES);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $type, int $i): array
    {
        $amount = $this->random->getInt(500, 250_000);

        return match (strtok($type, '.')) {
            'invoice' => ['invoice_id' => sprintf('inv_%05d', $i), 'customer_id' => sprintf('cus_%04d', $this->random->getInt(1, 400)), 'amount_cents' => $amount, 'currency' => 'USD'],
            'order' => ['order_id' => sprintf('ord_%05d', $i), 'items' => $this->random->getInt(1, 6), 'total_cents' => $amount, 'shipping' => ['carrier' => 'UPS', 'service' => 'ground']],
            default => ['customer_id' => sprintf('cus_%04d', $this->random->getInt(1, 400)), 'email' => sprintf('customer%d@example.com', $i), 'plan' => $this->random->pickArrayKeys(['starter' => 1, 'growth' => 1, 'scale' => 1], 1)[0]],
        };
    }
}
