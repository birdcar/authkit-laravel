<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * Workbench is a Testbench skeleton, not a consumer app `authkit:install` can
     * target, so the guard/provider entries `authkit:install` would normally write
     * into config/auth.php are set here instead. This is the minimum needed to
     * exercise the login -> callback -> logout flow via `composer serve`;
     * Phase 13 owns the full workbench build-out.
     */
    public function register(): void
    {
        config()->set('auth.guards.workos', [
            'driver' => 'workos',
            'provider' => 'workos',
        ]);

        // The guard's own provider is the single source of the user model: the
        // callback creates through the same class the guard later retrieves.
        config()->set('auth.providers.workos', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        // The minimum wiring for `composer serve` and workbench-touching tests
        // to have a real org model configured; Phase 13 owns the full build-out.
        config()->set('authkit.organization.model', Organization::class);
    }

    /**
     * Bootstrap services.
     *
     * The package registers `authkit.login`, `authkit.callback`, and
     * `authkit.logout` itself, so there are no routes to add here.
     */
    public function boot(): void
    {
        // The documented `php artisan dev` recipe for the events worker
        // (docs/quickstart.md Recipe A) — an app copies this into its own
        // AppServiceProvider::boot(). Inert here until opted in: the workbench
        // exercises the worker directly (`php artisan authkit:work`), and the
        // DevCommands class only exists on Laravel >= 13.16.
        //
        // \Illuminate\Foundation\DevCommands::artisan('authkit:work', 'authkit-events')->purple();
        // \Illuminate\Foundation\DevCommands::register('npx @workos/emulate', 'workos-emulate')->orange();
    }
}
