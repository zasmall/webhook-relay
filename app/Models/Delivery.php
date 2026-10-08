<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One event sent to one endpoint, across all of its attempts.
 *
 * @property string $id
 * @property string $event_id
 * @property string $endpoint_id
 * @property DeliveryStatus $status
 * @property int $attempts Attempts in the current run; reset by a replay
 * @property CarbonImmutable|null $next_attempt_at
 * @property int|null $last_status_code
 * @property CarbonImmutable|null $delivered_at
 * @property int $replay_count
 * @property CarbonImmutable|null $last_replayed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read Endpoint $endpoint
 */
#[Fillable(['event_id', 'endpoint_id', 'status'])]
final class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DeliveryStatus::Pending,
        'attempts' => 0,
        'replay_count' => 0,
    ];

    /**
     * Ids encode their creation time from now() (not the system clock), so a
     * delivery's id time always matches created_at. The delivery log relies on
     * that to turn date filters into primary-key ranges.
     */
    public function newUniqueId(): string
    {
        return strtolower((string) Str::ulid(now()));
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Includes soft-deleted endpoints, so history keeps its endpoint.
     *
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class)->withTrashed();
    }

    /**
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderBy('attempt');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_status_code' => 'integer',
            'delivered_at' => 'datetime',
            'replay_count' => 'integer',
            'last_replayed_at' => 'datetime',
        ];
    }
}
