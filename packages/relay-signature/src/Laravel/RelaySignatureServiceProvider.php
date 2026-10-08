<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class RelaySignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/relay-signature.php', 'relay-signature');
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('relay.signature', VerifyRelaySignature::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/relay-signature.php' => config_path('relay-signature.php'),
            ], 'relay-signature-config');
        }
    }
}
