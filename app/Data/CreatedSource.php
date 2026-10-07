<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Source;

final readonly class CreatedSource
{
    /**
     * @param  string  $plainTextToken  Only available at creation; Sanctum stores a hash.
     */
    public function __construct(
        public Source $source,
        public string $plainTextToken,
    ) {}
}
