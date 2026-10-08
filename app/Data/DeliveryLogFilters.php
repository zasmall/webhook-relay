<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;

final readonly class DeliveryLogFilters
{
    public function __construct(
        public ?DeliveryStatus $status = null,
        public ?string $endpointId = null,
        /** Exact type, "prefix.*" or "*". */
        public ?string $eventType = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'status' => $this->status?->value,
            'endpoint' => $this->endpointId,
            'event_type' => $this->eventType,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
        ], fn (?string $value): bool => $value !== null);
    }
}
