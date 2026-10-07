<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\SentAttempt;
use App\Enums\DeliveryStatus;
use App\Exceptions\UnsafeDestination;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Event;
use App\Support\OutboundAddressGuard;
use App\Support\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SendDelivery
{
    public function __construct(
        private readonly OutboundAddressGuard $addressGuard,
    ) {}

    /**
     * Makes one attempt to deliver and logs it. Returns null if there was
     * nothing to send (not pending, endpoint disabled or deleted, or another
     * worker claimed it first).
     *
     * The delivery is left "delivering"; HandleDeliveryResult decides what
     * happens next.
     */
    public function handle(Delivery $delivery): ?SentAttempt
    {
        $delivery->loadMissing(['event', 'endpoint']);
        $endpoint = $delivery->endpoint;

        if ($delivery->status !== DeliveryStatus::Pending) {
            return null;
        }

        if ($endpoint->trashed()) {
            $delivery->update(['status' => DeliveryStatus::Dead]);

            return null;
        }

        // Disabled endpoints keep their deliveries pending until re-enabled.
        if (! $endpoint->is_active) {
            return null;
        }

        if (! $this->claim($delivery)) {
            return null;
        }

        $body = self::body($delivery->event);
        $headers = $this->headers($delivery, $endpoint, $body);

        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $this->send($endpoint->url, $headers, $body);
        } catch (UnsafeDestination|ConnectionException $e) {
            $error = Str::limit($e->getMessage(), 1000);
        }

        $attempt = $delivery->attemptLog()->create([
            'attempt' => $delivery->attempts + 1,
            'request_headers' => $headers,
            'status_code' => $response?->status(),
            'response_body' => $response === null ? null : self::truncate($response->body()),
            'error' => $error,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return new SentAttempt($attempt, $response?->header('Retry-After'));
    }

    /**
     * The signed request body: an envelope around the event payload. Receivers
     * dedupe on "id", since delivery is at-least-once.
     */
    public static function body(Event $event): string
    {
        return json_encode([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => $event->received_at->toIso8601ZuluString(),
            'data' => $event->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Moves pending → delivering only if no other worker got there first, so a
     * delivery is never in flight twice even if two jobs run.
     */
    private function claim(Delivery $delivery): bool
    {
        $claimed = Delivery::whereKey($delivery->id)
            ->where('status', DeliveryStatus::Pending)
            ->update(['status' => DeliveryStatus::Delivering, 'updated_at' => now()]);

        if ($claimed === 1) {
            // Mirror the row without marking it dirty, so a later change back
            // to pending is saved.
            $delivery->status = DeliveryStatus::Delivering;
            $delivery->syncOriginalAttribute('status');
        }

        return $claimed === 1;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Delivery $delivery, Endpoint $endpoint, string $body): array
    {
        $secrets = $endpoint->signingSecrets();

        return [
            'Content-Type' => 'application/json',
            'User-Agent' => config()->string('relay.delivery.user_agent'),
            'X-Relay-Event-Id' => $delivery->event_id,
            'X-Relay-Event-Type' => $delivery->event->type,
            'X-Relay-Delivery-Id' => $delivery->id,
            config()->string('relay.signing.header') => WebhookSigner::header($body, $secrets, now()->getTimestamp()),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeDestination|ConnectionException
     */
    private function send(string $url, array $headers, string $body): Response
    {
        $pinned = $this->addressGuard->pin($url);

        return Http::withHeaders($headers)
            ->withBody($body, 'application/json')
            ->connectTimeout(config()->integer('relay.delivery.connect_timeout'))
            ->timeout(config()->integer('relay.delivery.timeout'))
            // A redirect could point at an internal address; never follow one.
            ->withoutRedirecting()
            ->when($pinned !== [], fn ($request) => $request->withOptions(['curl' => [CURLOPT_RESOLVE => $pinned]]))
            ->post($url);
    }

    /**
     * Cut to the configured byte limit on a character boundary, replacing any
     * invalid UTF-8 so the text column accepts it.
     */
    private static function truncate(string $body): string
    {
        $valid = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return mb_strcut($valid, 0, config()->integer('relay.delivery.response_body_limit'), 'UTF-8');
    }
}
