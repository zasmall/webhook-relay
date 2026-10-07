<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\HandleDeliveryResult;
use App\Actions\SendDelivery;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Sends one delivery and applies the retry policy.
 *
 * Uniqueness: the lock on the delivery id is held while the job waits on the
 * queue (including delayed retries and rate-limit releases) and released when
 * it starts running, so the running job can schedule its own retry.
 * SendDelivery's atomic claim guards the request itself.
 */
final class DeliverWebhook implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public readonly string $deliveryId;

    public readonly string $endpointId;

    /**
     * Rate-limit releases are unlimited and don't count as attempts; retries
     * are counted on the delivery row. A real exception fails the job once.
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(Delivery $delivery)
    {
        $this->deliveryId = $delivery->id;
        $this->endpointId = $delivery->endpoint_id;

        $this->onQueue('deliveries');

        $this->timeout = config()->integer('relay.delivery.connect_timeout') + config()->integer('relay.delivery.timeout') + 5;

        // Must outlast the longest wait on the queue, or the sweep could queue
        // a second copy (harmless thanks to the claim, but wasted work).
        $this->uniqueFor = max(config()->integer('relay.retry.max_delay'), config()->integer('relay.retry.max_retry_after'))
            + $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('deliveries')];
    }

    public function handle(SendDelivery $sendDelivery, HandleDeliveryResult $handleResult): void
    {
        $delivery = Delivery::find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        $sent = $sendDelivery->handle($delivery);

        if ($sent !== null) {
            $handleResult->handle($delivery, $sent);
        }
    }

    /**
     * An unexpected exception or timeout mid-attempt would leave the delivery
     * stuck in "delivering"; put it back so it can be sent again.
     */
    public function failed(?Throwable $exception): void
    {
        Delivery::whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::Delivering)
            ->update(['status' => DeliveryStatus::Pending, 'updated_at' => now()]);
    }
}
