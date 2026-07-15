<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Tests;

use Cbox\Billing\Mollie\MollieServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [MollieServiceProvider::class];
    }
}
