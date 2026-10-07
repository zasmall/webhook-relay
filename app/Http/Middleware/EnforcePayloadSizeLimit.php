<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects oversized request bodies with 413 before they are parsed. Measures
 * the raw body, since Content-Length can be missing or wrong.
 */
final class EnforcePayloadSizeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = config()->integer('relay.ingest.max_payload_bytes');

        if (strlen($request->getContent()) > $limit) {
            return response()->json([
                'message' => "The request body may not be larger than {$limit} bytes.",
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
