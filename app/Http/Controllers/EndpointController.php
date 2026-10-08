<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateEndpoint;
use App\Actions\ReplayDeliveries;
use App\Actions\RotateEndpointSecret;
use App\Actions\UpdateEndpoint;
use App\Http\Requests\Endpoints\StoreEndpointRequest;
use App\Http\Requests\Endpoints\UpdateEndpointRequest;
use App\Http\Resources\EndpointResource;
use App\Models\Endpoint;
use App\Queries\EndpointStatsQuery;
use Carbon\CarbonInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class EndpointController extends Controller
{
    public function __construct(
        private readonly EndpointStatsQuery $statsQuery,
    ) {}

    public function index(Request $request): Response
    {
        $endpoints = Endpoint::latest()->latest('id')->get();
        $stats = $this->statsQuery->forEndpoints(array_values($endpoints->modelKeys()));

        return Inertia::render('endpoints/Index', [
            'endpoints' => $endpoints->map(
                fn (Endpoint $endpoint) => EndpointResource::make($endpoint)->withStats($stats[$endpoint->id])->resolve($request),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('endpoints/Create');
    }

    public function store(StoreEndpointRequest $request, CreateEndpoint $createEndpoint): RedirectResponse
    {
        $endpoint = $createEndpoint->handle(
            $request->string('url')->value(),
            $request->eventTypes(),
            $request->filled('description') ? $request->string('description')->value() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint created. Reveal its signing secret to configure your receiver.')]);

        return to_route('endpoints.show', $endpoint);
    }

    public function show(Request $request, Endpoint $endpoint): Response
    {
        return $this->renderShow($request, $endpoint, secret: null);
    }

    /**
     * The show page with the signing secret visible. Behind password
     * confirmation (RequirePassword) in routes/web.php.
     */
    public function secret(Request $request, Endpoint $endpoint): Response
    {
        return $this->renderShow($request, $endpoint, secret: $endpoint->secret);
    }

    public function update(UpdateEndpointRequest $request, Endpoint $endpoint, UpdateEndpoint $updateEndpoint): RedirectResponse
    {
        $updateEndpoint->handle($endpoint, $request->endpointAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint updated.')]);

        return to_route('endpoints.show', $endpoint);
    }

    public function destroy(Endpoint $endpoint): RedirectResponse
    {
        $endpoint->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint deleted.')]);

        return to_route('endpoints.index');
    }

    public function rotateSecret(Endpoint $endpoint, RotateEndpointSecret $rotateEndpointSecret): RedirectResponse
    {
        $rotateEndpointSecret->handle($endpoint);

        $grace = CarbonInterval::seconds(config()->integer('relay.endpoints.secret_rotation_grace'))->cascade()->forHumans();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Secret rotated. The previous secret keeps working for :grace.', ['grace' => $grace])]);

        return to_route('endpoints.show', $endpoint);
    }

    /**
     * Replays every dead delivery for the endpoint. Single and selected
     * replays live in the delivery log.
     */
    public function replay(Endpoint $endpoint, ReplayDeliveries $replayDeliveries): RedirectResponse
    {
        $replayed = $replayDeliveries->handle($endpoint);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} No dead deliveries to replay.|{1} Replaying 1 delivery.|[2,*] Replaying :count deliveries.',
            $replayed,
        )]);

        return to_route('endpoints.show', $endpoint);
    }

    private function renderShow(Request $request, Endpoint $endpoint, ?string $secret): Response
    {
        $stats = $this->statsQuery->forEndpoints([$endpoint->id])[$endpoint->id];

        return Inertia::render('endpoints/Show', [
            'endpoint' => EndpointResource::make($endpoint)->withStats($stats)->resolve($request),
            'secret' => $secret,
            'deadDeliveries' => $stats->dead,
        ]);
    }
}
