<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IngestResult;
use App\Models\Event;
use App\Models\Source;
use Illuminate\Database\UniqueConstraintViolationException;

final class IngestEvent
{
    /**
     * Store an event, or return the existing one if this source already sent
     * the idempotency key.
     *
     * This inserts first and never checks beforehand. The unique index on
     * (source_id, idempotency_key) is the only arbiter, so concurrent retries
     * can't both pass a check and create duplicates. A duplicate returns the
     * original event even if the retry's type or payload differ.
     */
    public function handle(Source $source, string $type, object $payload, string $idempotencyKey): IngestResult
    {
        try {
            $event = $source->events()->create([
                'type' => $type,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
            ]);

            return new IngestResult($event, created: true);
        } catch (UniqueConstraintViolationException) {
            // MySQL only raises the violation once the competing insert has
            // committed, so the original row is visible to this read.
            $event = $source->events()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return new IngestResult($event, created: false);
        }
    }
}
