<?php

namespace App\Providers;

use App\Jobs\DeliverWebhook;
use App\Support\RetrySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RetrySchedule::class, fn (): RetrySchedule => new RetrySchedule(
            baseDelay: config()->integer('relay.retry.base_delay'),
            maxDelay: config()->integer('relay.retry.max_delay'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRelay();
    }

    protected function configureRelay(): void
    {
        // Protects receivers from bursts; keyed per endpoint. Rate-limited jobs
        // are released back onto the queue and don't count as attempts.
        RateLimiter::for('deliveries', fn (DeliverWebhook $job): Limit => Limit::perMinute(config()->integer('relay.rate_limit.per_minute'))
            ->by('endpoint:'.$job->endpointId));

        // Run the scheduler (relay:sweep) alongside `composer dev`.
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
