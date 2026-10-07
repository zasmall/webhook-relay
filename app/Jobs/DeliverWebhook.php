<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendDelivery;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one delivery. ShouldBeUnique on the delivery id keeps a second copy
 * off the queue; SendDelivery's atomic claim guards the in-flight request.
 */
final class DeliverWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Retries are scheduled by the relay (M4), not by re-running the job. */
    public int $tries = 1;

    public int $timeout;

    /** Long enough to outlive any single attempt if a worker dies mid-job. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $deliveryId,
    ) {
        $this->onQueue('deliveries');
        $this->timeout = config()->integer('relay.delivery.connect_timeout') + config()->integer('relay.delivery.timeout') + 5;
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(SendDelivery $sendDelivery): void
    {
        $delivery = Delivery::find($this->deliveryId);

        if ($delivery !== null) {
            $sendDelivery->handle($delivery);
        }
    }

    /**
     * An unexpected exception mid-attempt would leave the delivery stuck in
     * "delivering"; put it back so it can be sent again.
     */
    public function failed(?Throwable $exception): void
    {
        Delivery::whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::Delivering)
            ->update(['status' => DeliveryStatus::Pending, 'updated_at' => now()]);
    }
}
