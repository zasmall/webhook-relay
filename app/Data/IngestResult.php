<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Event;

final readonly class IngestResult
{
    public function __construct(
        public Event $event,
        public bool $created,
    ) {}
}
