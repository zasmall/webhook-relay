<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateEndpoint;
use App\Actions\RotateEndpointSecret;
use App\Actions\UpdateEndpoint;
use App\Http\Requests\Endpoints\StoreEndpointRequest;
use App\Http\Requests\Endpoints\UpdateEndpointRequest;
use App\Http\Resources\EndpointResource;
use App\Models\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class EndpointController
{
    public function index(): AnonymousResourceCollection
    {
        return EndpointResource::collection(Endpoint::latest()->latest('id')->paginate(50));
    }

    public function store(StoreEndpointRequest $request, CreateEndpoint $createEndpoint): JsonResponse
    {
        $endpoint = $createEndpoint->handle(
            $request->string('url')->value(),
            $request->eventTypes(),
            $request->filled('description') ? $request->string('description')->value() : null,
        );

        return EndpointResource::make($endpoint)->withSecret()->response()->setStatusCode(201);
    }

    public function show(Endpoint $endpoint): EndpointResource
    {
        return EndpointResource::make($endpoint);
    }

    public function update(UpdateEndpointRequest $request, Endpoint $endpoint, UpdateEndpoint $updateEndpoint): EndpointResource
    {
        return EndpointResource::make($updateEndpoint->handle($endpoint, $request->endpointAttributes()));
    }

    public function destroy(Endpoint $endpoint): Response
    {
        $endpoint->delete();

        return response()->noContent();
    }

    public function rotateSecret(Endpoint $endpoint, RotateEndpointSecret $rotateEndpointSecret): EndpointResource
    {
        return EndpointResource::make($rotateEndpointSecret->handle($endpoint))->withSecret();
    }
}
