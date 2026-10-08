<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReplayDeliveries;
use App\Actions\ReplaySelectedDeliveries;
use App\Enums\DeliveryStatus;
use App\Http\Requests\Deliveries\DeliveryLogRequest;
use App\Http\Requests\Deliveries\ReplaySelectedDeliveriesRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Queries\DeliveryLogQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DeliveryController extends Controller
{
    public function index(DeliveryLogRequest $request, DeliveryLogQuery $log): Response
    {
        $filters = $request->filters();
        $page = $log->paginate($filters);

        return Inertia::render('deliveries/Index', [
            'deliveries' => [
                'data' => DeliveryResource::collection($page->items())->resolve($request),
                'next_page_url' => $page->nextPageUrl(),
                'prev_page_url' => $page->previousPageUrl(),
            ],
            'filters' => $filters->toQuery(),
            'statuses' => array_column(DeliveryStatus::cases(), 'value'),
            'endpoints' => Endpoint::withTrashed()
                ->orderBy('url')
                ->get(['id', 'url', 'description', 'deleted_at'])
                ->map(fn (Endpoint $endpoint): array => [
                    'id' => $endpoint->id,
                    'label' => $endpoint->description ? "{$endpoint->description} ({$endpoint->url})" : $endpoint->url,
                    'deleted' => $endpoint->trashed(),
                ]),
        ]);
    }

    public function show(Request $request, Delivery $delivery): Response
    {
        $delivery->load(['event', 'endpoint', 'attemptLog']);

        return Inertia::render('deliveries/Show', [
            'delivery' => DeliveryResource::make($delivery)->resolve($request),
            'event' => [
                'id' => $delivery->event->id,
                'type' => $delivery->event->type,
                'received_at' => $delivery->event->received_at->toIso8601ZuluString(),
                // Pretty-printed for operators only; payloads are never logged.
                'payload' => json_encode($delivery->event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'endpointActive' => $delivery->endpoint->is_active && ! $delivery->endpoint->trashed(),
        ]);
    }

    public function replay(Delivery $delivery, ReplayDeliveries $replayDeliveries): RedirectResponse
    {
        $replayDeliveries->handle($delivery->endpoint, [$delivery->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Replaying delivery.')]);

        return back();
    }

    public function replaySelected(ReplaySelectedDeliveriesRequest $request, ReplaySelectedDeliveries $replaySelected): RedirectResponse
    {
        $summary = $replaySelected->handle($request->deliveryIds());

        $message = trans_choice('{0} Nothing to replay.|{1} Replaying 1 delivery.|[2,*] Replaying :count deliveries.', $summary->replayed);

        if ($summary->skipped > 0) {
            $message .= ' '.trans_choice('{1} Skipped 1: its endpoint is disabled or deleted.|[2,*] Skipped :count: their endpoint is disabled or deleted.', $summary->skipped);
        }

        Inertia::flash('toast', ['type' => $summary->skipped > 0 ? 'warning' : 'success', 'message' => $message]);

        return back();
    }
}
