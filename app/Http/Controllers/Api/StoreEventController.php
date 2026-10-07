<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\IngestEvent;
use App\Http\Requests\Api\StoreEventRequest;
use App\Http\Resources\EventResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class StoreEventController
{
    /**
     * 202 for a new event, 200 with the original event for a duplicate key.
     */
    public function __invoke(StoreEventRequest $request, IngestEvent $ingestEvent): JsonResponse
    {
        $result = $ingestEvent->handle(
            $request->source(),
            $request->eventType(),
            $request->payload(),
            $request->idempotencyKey(),
        );

        return EventResource::make($result->event)
            ->response()
            ->setStatusCode($result->created ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }
}
