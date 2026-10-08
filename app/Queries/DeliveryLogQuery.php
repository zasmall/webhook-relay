<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\DeliveryLogFilters;
use App\Models\Delivery;
use App\Support\EventTypePattern;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

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
            ->when($filters->from, fn (Builder $query, $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($filters->to, fn (Builder $query, $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when(
                $filters->eventType !== null && $filters->eventType !== EventTypePattern::WILDCARD,
                fn (Builder $query) => $query->whereIn('event_id', fn ($events) => $this->eventsOfType($events, (string) $filters->eventType)),
            );
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
