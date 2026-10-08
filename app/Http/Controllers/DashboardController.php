<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EndpointHealth;
use App\Http\Resources\EndpointResource;
use App\Models\Endpoint;
use App\Queries\DashboardSummaryQuery;
use App\Queries\EndpointStatsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSummaryQuery $summary, EndpointStatsQuery $statsQuery): Response
    {
        $endpoints = Endpoint::orderBy('url')->get();
        $stats = $statsQuery->forEndpoints(array_values($endpoints->modelKeys()));

        $health = fn (Endpoint $endpoint): EndpointHealth => EndpointHealth::for($endpoint->is_active, $endpoint->consecutive_failures, $stats[$endpoint->id]);

        // Failing first (still taking traffic and losing it), then disabled.
        $needsAttention = $endpoints
            ->filter(fn (Endpoint $endpoint): bool => $health($endpoint)->needsAttention())
            ->sortBy([
                fn (Endpoint $a, Endpoint $b): int => ($health($a) === EndpointHealth::Failing ? 0 : 1) <=> ($health($b) === EndpointHealth::Failing ? 0 : 1),
                fn (Endpoint $a, Endpoint $b): int => $a->url <=> $b->url,
            ])
            ->map(fn (Endpoint $endpoint) => EndpointResource::make($endpoint)->withStats($stats[$endpoint->id])->resolve($request))
            ->values();

        return Inertia::render('Dashboard', [
            'summary' => $summary->get(),
            'endpointCount' => $endpoints->count(),
            'needsAttention' => $needsAttention,
        ]);
    }
}
