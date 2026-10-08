# relay-signature

Signs and verifies Webhook Relay's `X-Relay-Signature` header. It's used by the relay to sign deliveries, and by receivers (with the included Laravel middleware) to verify them.

## The scheme

```
X-Relay-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "{t}.{raw body}">[,v1=...]
```

- The relay sends one `v1` per active endpoint secret. During a secret rotation, both the new and the old secret sign each delivery.
- A request passes if any `v1` matches any of the receiver's secrets. That means both sides can rotate without dropping webhooks.
- Timestamps more than the tolerance (300 seconds by default) away from the receiver's clock are rejected, in either direction. This blocks replayed captures.
- Comparisons use `hash_equals`.
- Unknown schemes (`v0`, `v2`, …) are ignored, so a future scheme can be added alongside `v1`.

Verify the **raw** body. Re-encoding parsed JSON changes the bytes and breaks the signature.

## Laravel

The service provider is auto-discovered. Set the endpoint secret the relay gave you:

```dotenv
RELAY_WEBHOOK_SECRET=whsec_...
# While rotating on your side, accept both:
# RELAY_WEBHOOK_SECRET=whsec_new,whsec_old
```

Protect the route. It should be stateless and exempt from CSRF; `routes/api.php` is the natural home.

```php
Route::post('webhooks/relay', RelayWebhookController::class)->middleware('relay.signature');

// Different secrets per route:
Route::post('webhooks/billing', ...)->middleware('relay.signature:services.billing.relay_secrets');
```

| Response | When                                                   |
| -------- | ------------------------------------------------------ |
| 400      | Header missing or malformed                            |
| 401      | No signature matches, or the timestamp is out of range |
| 500      | No secret configured (fails loudly, never open)        |

To change the tolerance, publish the config with `php artisan vendor:publish --tag=relay-signature-config` or set `RELAY_WEBHOOK_TOLERANCE`.

**Deduplicate in your app.** Delivery is at-least-once, so store the event `id` from the body under a unique index and treat a duplicate as success. Don't use a cache-based "seen" check for this: it drops a legitimate retry if your handler fails after marking the event as seen.

## Without Laravel

```php
use Zasmall\RelaySignature\Verifier;
use Zasmall\RelaySignature\Exceptions\InvalidSignature;

try {
    Verifier::verify($_SERVER['HTTP_X_RELAY_SIGNATURE'] ?? null, file_get_contents('php://input'), ['whsec_...'], time());
} catch (InvalidSignature) {
    http_response_code(401);
    exit;
}
```

## Tests

```bash
composer install
vendor/bin/pest
```
