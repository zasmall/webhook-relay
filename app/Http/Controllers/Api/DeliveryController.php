<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ReplayDeliveries;
use App\Enums\DeliveryStatus;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class DeliveryController
{
    /**
     * An endpoint's deliveries, newest first, optionally filtered by status.
     */
    public function index(Request $request, Endpoint $endpoint): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
        ]);

        $deliveries = $endpoint->deliveries()
            ->with('event:id,type')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return DeliveryResource::collection($deliveries);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return DeliveryResource::make($delivery->load(['event:id,type', 'attemptLog']));
    }

    public function replay(Delivery $delivery, ReplayDeliveries $replayDeliveries): JsonResponse
    {
        $replayed = $replayDeliveries->handle($delivery->endpoint, [$delivery->id]);

        return response()->json(['replayed' => $replayed]);
    }
}
