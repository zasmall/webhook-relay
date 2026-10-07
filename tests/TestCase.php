<?php

namespace Tests;

use App\Support\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Fakes\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeHostResolver $hosts;

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia page tests shouldn't depend on a frontend build.
        $this->withoutVite();

        $this->hosts = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->hosts);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
