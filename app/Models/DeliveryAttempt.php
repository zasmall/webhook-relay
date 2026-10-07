<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One HTTP attempt for a delivery. The log is append-only: updating or
 * deleting an attempt through the model throws.
 *
 * @property int $id
 * @property string $delivery_id
 * @property int $attempt Lifetime sequence for the delivery; continues across replays
 * @property array<string, string> $request_headers
 * @property int|null $status_code
 * @property string|null $response_body
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 */
#[Fillable(['delivery_id', 'attempt', 'request_headers', 'status_code', 'response_body', 'error', 'duration_ms'])]
final class DeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function succeeded(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Delivery attempts are append-only.'));
        self::deleting(fn () => throw new LogicException('Delivery attempts are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
