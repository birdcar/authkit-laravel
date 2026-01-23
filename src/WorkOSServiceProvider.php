<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\AuditMiddleware;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSGuard;
use WorkOS\AuthKit\Commands\EventsListenCommand;
use WorkOS\AuthKit\Commands\InstallCommand;
use WorkOS\AuthKit\Commands\PruneSessionsCommand;
use WorkOS\AuthKit\Commands\SyncUsersCommand;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;
use WorkOS\AuthKit\Http\Middleware\CheckOrganization;
use WorkOS\AuthKit\Http\Middleware\CheckPermission;
use WorkOS\AuthKit\Http\Middleware\CheckRole;
use WorkOS\AuthKit\Http\Middleware\DetectImpersonation;
use WorkOS\AuthKit\Http\Middleware\EnsureWorkOSAuthenticated;
use WorkOS\AuthKit\Http\Middleware\ShareWorkOSData;
use WorkOS\AuthKit\Listeners\SyncMembershipFromWebhook;
use WorkOS\AuthKit\Listeners\SyncOrganizationFromWebhook;
use WorkOS\AuthKit\Listeners\SyncUserFromWebhook;

class WorkOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workos.php', 'workos');

        $this->app->singleton(SessionManager::class, function ($app) {
            return new SessionManager($app['session.store']);
        });

        $this->app->singleton('workos', function ($app) {
            $this->configureWorkOSSdk();

            return new WorkOS($app->make(SessionManager::class));
        });

        $this->app->alias('workos', WorkOS::class);

        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                new \WorkOS\AuditLogs,
                $app->make(SessionManager::class)
            );
        });
    }

    public function boot(): void
    {
        $this->configureGuard();
        $this->configureMiddleware();
        $this->configureBladeDirectives();
        $this->configureMigrations();
        $this->configurePublishing();
        $this->configureRoutes();
        $this->configureWebhooks();
        $this->configureEventListeners();
        $this->configureCommands();
    }

    protected function configureWorkOSSdk(): void
    {
        $config = config('workos');

        if ($config['api_key']) {
            \WorkOS\WorkOS::setApiKey($config['api_key']);
        }

        if ($config['client_id']) {
            \WorkOS\WorkOS::setClientId($config['client_id']);
        }
    }

    protected function configureGuard(): void
    {
        Auth::extend('workos', function ($app, $name, array $config) {
            return new WorkOSGuard(
                Auth::createUserProvider($config['provider'] ?? null),
                $app->make(SessionManager::class),
                $app['request']
            );
        });
    }

    protected function configureMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('workos.auth', EnsureWorkOSAuthenticated::class);
        $router->aliasMiddleware('workos.role', CheckRole::class);
        $router->aliasMiddleware('workos.permission', CheckPermission::class);
        $router->aliasMiddleware('workos.impersonation', DetectImpersonation::class);
        $router->aliasMiddleware('workos.organization', CheckOrganization::class);
        $router->aliasMiddleware('workos.audit', AuditMiddleware::class);
        $router->aliasMiddleware('workos.inertia', ShareWorkOSData::class);
    }

    protected function configureBladeDirectives(): void
    {
        Blade::if('workosRole', function (string $role) {
            /** @var \Illuminate\Contracts\Auth\Guard $guard */
            $guard = auth();
            $user = $guard->user();

            if ($user && method_exists($user, 'hasWorkOSRole')) {
                return $user->hasWorkOSRole($role);
            }

            return false;
        });

        Blade::if('workosPermission', function (string $permission) {
            /** @var \Illuminate\Contracts\Auth\Guard $guard */
            $guard = auth();
            $user = $guard->user();

            if ($user && method_exists($user, 'hasWorkOSPermission')) {
                return $user->hasWorkOSPermission($permission);
            }

            return false;
        });

        Blade::if('impersonating', fn () => $this->app->make(SessionManager::class)->isImpersonating()
        );
    }

    protected function configureMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function configurePublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/workos.php' => config_path('workos.php'),
        ], 'workos-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'workos-migrations');
    }

    protected function configureRoutes(): void
    {
        if (! config('workos.routes.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('workos.routes.prefix', 'auth'),
            'middleware' => config('workos.routes.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        if (config('workos.features.organizations', true)) {
            Route::group([
                'prefix' => config('workos.routes.organizations_prefix', 'organizations'),
                'middleware' => array_merge(
                    (array) config('workos.routes.middleware', ['web']),
                    ['auth:'.config('workos.guard', 'workos')]
                ),
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/organizations.php');
            });
        }
    }

    protected function configureWebhooks(): void
    {
        if (! config('workos.webhooks.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('workos.webhooks.prefix', 'webhooks/workos'),
            'middleware' => [],
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php'));
    }

    protected function configureEventListeners(): void
    {
        if (! config('workos.webhooks.sync_enabled', true)) {
            return;
        }

        Event::listen(WorkOSUserCreated::class, [SyncUserFromWebhook::class, 'handle']);
        Event::listen(WorkOSUserUpdated::class, [SyncUserFromWebhook::class, 'handle']);
        Event::listen(WorkOSOrganizationCreated::class, [SyncOrganizationFromWebhook::class, 'handle']);
        Event::listen(WorkOSOrganizationUpdated::class, [SyncOrganizationFromWebhook::class, 'handle']);
        Event::listen(WorkOSMembershipCreated::class, [SyncMembershipFromWebhook::class, 'handleCreated']);
        Event::listen(WorkOSMembershipUpdated::class, [SyncMembershipFromWebhook::class, 'handleUpdated']);
        Event::listen(WorkOSMembershipDeleted::class, [SyncMembershipFromWebhook::class, 'handleDeleted']);
    }

    protected function configureCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            SyncUsersCommand::class,
            EventsListenCommand::class,
            PruneSessionsCommand::class,
        ]);
    }
}
