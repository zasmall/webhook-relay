<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ReplayDeliveries;
use App\Http\Requests\Deliveries\ReplayDeliveriesRequest;
use App\Models\Endpoint;
use Illuminate\Http\JsonResponse;

final class ReplayEndpointDeliveriesController
{
    public function __invoke(ReplayDeliveriesRequest $request, Endpoint $endpoint, ReplayDeliveries $replayDeliveries): JsonResponse
    {
        return response()->json([
            'replayed' => $replayDeliveries->handle($endpoint, $request->deliveryIds()),
        ]);
    }
}
