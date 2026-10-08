<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zasmall\RelaySignature\Exceptions\InvalidSignature;
use Zasmall\RelaySignature\Exceptions\MalformedSignature;
use Zasmall\RelaySignature\Exceptions\MissingSignature;
use Zasmall\RelaySignature\Signer;
use Zasmall\RelaySignature\Verifier;

/**
 * Rejects requests whose X-Relay-Signature doesn't verify against the raw
 * body. Usage:
 *
 *     Route::post('webhooks/relay', ...)->middleware('relay.signature');
 *
 * Pass a config key to use different secrets per route:
 *
 *     ->middleware('relay.signature:services.billing_relay.secrets')
 *
 * Responds 400 to a missing or malformed header and 401 to a mismatch or a
 * stale timestamp, without saying which check failed.
 */
final class VerifyRelaySignature
{
    public function handle(Request $request, Closure $next, string $secretsKey = 'relay-signature.secrets'): Response
    {
        try {
            Verifier::verify(
                $request->header(Signer::HEADER),
                $request->getContent(),
                $this->secrets($secretsKey),
                time(),
                (int) config('relay-signature.tolerance', 300),
            );
        } catch (InvalidSignature $e) {
            $status = $e instanceof MissingSignature || $e instanceof MalformedSignature
                ? Response::HTTP_BAD_REQUEST
                : Response::HTTP_UNAUTHORIZED;

            return response()->json(['message' => 'Invalid webhook signature.'], $status);
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function secrets(string $key): array
    {
        $secrets = config($key);

        if (is_string($secrets)) {
            $secrets = [$secrets];
        }

        return is_array($secrets) ? array_values(array_filter($secrets, is_string(...))) : [];
    }
}
