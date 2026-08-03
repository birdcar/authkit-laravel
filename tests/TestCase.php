<?php

declare(strict_types=1);

namespace Authkit\Authkit\Tests;

use Authkit\Authkit\AuthkitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthkitServiceProvider::class,
        ];
    }
}
