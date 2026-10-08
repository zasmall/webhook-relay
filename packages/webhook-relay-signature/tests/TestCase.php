<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zasmall\RelaySignature\Laravel\RelaySignatureServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RelaySignatureServiceProvider::class];
    }
}
