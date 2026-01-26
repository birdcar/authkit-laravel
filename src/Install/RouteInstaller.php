<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;

class RouteInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        // Routes are loaded from package automatically via config
        // This just ensures routes.enabled = true in config
        $command->info('Auth routes enabled: /auth/login, /auth/callback, /auth/logout');
    }

    public function describe(): string
    {
        return 'Login, callback, and logout routes';
    }
}
