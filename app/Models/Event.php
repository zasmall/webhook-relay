<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event published by a source. Events are immutable once received.
 *
 * @property string $id
 * @property string $source_id
 * @property string $type
 * @property object $payload
 * @property string $idempotency_key
 * @property Carbon $received_at
 */
#[Fillable(['source_id', 'type', 'payload', 'idempotency_key'])]
final class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids;

    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Decoded as objects, not arrays, so an empty `{}` in the payload is
            // stored and delivered as `{}` rather than `[]`.
            'payload' => 'object',
            'received_at' => 'datetime',
        ];
    }
}
