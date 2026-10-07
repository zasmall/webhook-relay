<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EndpointDisabledReason;
use App\Support\EventTypePattern;
use Carbon\CarbonImmutable;
use Database\Factories\EndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subscriber URL that receives signed deliveries for matching event types.
 *
 * @property string $id
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property list<string> $event_types
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $disabled_at
 * @property EndpointDisabledReason|null $disabled_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['url', 'description', 'event_types'])]
#[Hidden(['secret', 'previous_secret'])]
final class Endpoint extends Model
{
    /** @use HasFactory<EndpointFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const SECRET_PREFIX = 'whsec_';

    /**
     * Mirrors the column defaults so a freshly created model has them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'consecutive_failures' => 0,
    ];

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Secrets that should sign a delivery right now: the current secret, plus
     * the previous one while its rotation grace window is open.
     *
     * @return non-empty-list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Endpoints whose patterns match the event type, matched in SQL.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function subscribedTo(Builder $query, string $type): void
    {
        $query->whereJsonOverlaps('event_types', EventTypePattern::candidatesFor($type));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'event_types' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'disabled_reason' => EndpointDisabledReason::class,
        ];
    }
}
