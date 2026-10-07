<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CreateDeliveries;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates the event's deliveries and queues each one. Idempotent, so ingest
 * dispatches it again on a duplicate key to repair a lost dispatch.
 */
final class FanOutEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly Event $event,
    ) {
        $this->onQueue('fanout');
        $this->afterCommit();
    }

    public function handle(CreateDeliveries $createDeliveries): void
    {
        foreach ($createDeliveries->handle($this->event) as $delivery) {
            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
