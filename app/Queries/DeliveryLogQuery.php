<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\DeliveryLogFilters;
use App\Models\Delivery;
use App\Support\EventTypePattern;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The filtered, newest-first delivery log. Cursor pagination keeps deep pages
 * as cheap as the first one; ULID ids sort by creation time.
 */
final class DeliveryLogQuery
{
    /**
     * @return CursorPaginator<int, Delivery>
     */
    public function paginate(DeliveryLogFilters $filters, int $perPage = 50): CursorPaginator
    {
        return $this->query($filters)
            ->with(['event:id,type', 'endpoint:id,url,description,deleted_at'])
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Builder<Delivery>
     */
    public function query(DeliveryLogFilters $filters): Builder
    {
        return Delivery::query()
            ->when($filters->status, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters->endpointId, fn (Builder $query, string $id) => $query->where('endpoint_id', $id))
            // ULIDs start with their creation time, so a date range is also a
            // primary-key range: MySQL range-scans the PK instead of walking it
            // backwards from the newest row. created_at keeps the edges exact.
            ->when($filters->from, fn (Builder $query, $from) => $query
                ->where('id', '>=', self::ulidBound($from->startOfDay(), '0'))
                ->where('created_at', '>=', $from->startOfDay()))
            ->when($filters->to, fn (Builder $query, $to) => $query
                ->where('id', '<=', self::ulidBound($to->endOfDay(), 'z'))
                ->where('created_at', '<=', $to->endOfDay()))
            ->when(
                $filters->eventType !== null && $filters->eventType !== EventTypePattern::WILDCARD,
                fn (Builder $query) => $query->whereIn('event_id', fn ($events) => $this->eventsOfType($events, (string) $filters->eventType)),
            );
    }

    /**
     * The smallest ("0") or largest ("z") lowercase ULID for an instant: its
     * 10-character timestamp followed by 16 copies of the fill character.
     * A second's ULIDs span its whole millisecond range, so the upper bound
     * uses the last millisecond.
     */
    public static function ulidBound(CarbonInterface $at, string $fill): string
    {
        $time = $fill === 'z' ? $at->endOfSecond() : $at->startOfSecond();

        return substr(strtolower((string) Str::ulid($time)), 0, 10).str_repeat($fill, 16);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $events
     */
    private function eventsOfType($events, string $pattern): void
    {
        $events->select('id')->from('events');

        if (! str_ends_with($pattern, '.*')) {
            $events->where('type', $pattern);

            return;
        }

        // "invoice.*" matches anything under "invoice.". Escape LIKE
        // wildcards: "_" is legal in event types.
        $prefix = addcslashes(substr($pattern, 0, -1), '\\%_');
        $events->where('type', 'like', $prefix.'%');
    }
}
