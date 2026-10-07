<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Source;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sources and operators both authenticate with Sanctum tokens. This keeps each
 * kind of token on its own routes: `token.for:source` or `token.for:user`.
 */
final class EnsureTokenBelongsTo
{
    private const TOKENABLES = [
        'source' => Source::class,
        'user' => User::class,
    ];

    public function handle(Request $request, Closure $next, string $tokenable): Response
    {
        $class = self::TOKENABLES[$tokenable] ?? throw new InvalidArgumentException("Unknown tokenable [{$tokenable}].");

        if (! $request->user('sanctum') instanceof $class) {
            return response()->json(['message' => 'This token cannot access this resource.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
