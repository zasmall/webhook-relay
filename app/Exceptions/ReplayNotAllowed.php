<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ReplayNotAllowed extends RuntimeException
{
    public static function endpointDisabled(Endpoint $endpoint): self
    {
        return new self("Endpoint {$endpoint->id} is disabled. Enable it before replaying deliveries.");
    }

    public static function endpointDeleted(Endpoint $endpoint): self
    {
        return new self("Endpoint {$endpoint->id} has been deleted.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back();
    }
}
