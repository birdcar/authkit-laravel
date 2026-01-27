<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\AuditMiddleware;
use WorkOS\AuthKit\Auth\CookieSessionManager;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\SessionManagerInterface;
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
use WorkOS\AuthKit\Http\Middleware\SetCurrentOrganization;
use WorkOS\AuthKit\Http\Middleware\ShareWorkOSData;
use WorkOS\AuthKit\Install\AuthSystemInstaller;
use WorkOS\AuthKit\Install\EnvManager;
use WorkOS\AuthKit\Install\LaravelWorkosMigrator;
use WorkOS\AuthKit\Install\MigrationPlanGenerator;
use WorkOS\AuthKit\Install\RouteInstaller;
use WorkOS\AuthKit\Install\WebhookInstaller;
use WorkOS\AuthKit\Install\WizardFlow;
use WorkOS\AuthKit\Listeners\SyncMembershipFromWebhook;
use WorkOS\AuthKit\Listeners\SyncOrganizationFromWebhook;
use WorkOS\AuthKit\Listeners\SyncUserFromWebhook;
use WorkOS\AuthKit\Support\EnvironmentDetector;

class WorkOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workos.php', 'workos');

        $this->registerSessionManager();

        $this->app->singleton('workos', function ($app) {
            $this->configureWorkOSSdk();

            return new WorkOS($app->make(SessionManagerInterface::class));
        });

        $this->app->alias('workos', WorkOS::class);

        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                new \WorkOS\AuditLogs,
                $app->make(SessionManagerInterface::class)
            );
        });

        $this->app->singleton(EnvironmentDetector::class, function ($app) {
            return new EnvironmentDetector(
                $app->make('files'),
                $app->basePath()
            );
        });

        $this->registerInstallers();
    }

    protected function registerInstallers(): void
    {
        $this->app->singleton(RouteInstaller::class);
        $this->app->singleton(AuthSystemInstaller::class);
        $this->app->singleton(WebhookInstaller::class);
        $this->app->singleton(LaravelWorkosMigrator::class);

        $this->app->singleton(EnvManager::class, function ($app) {
            return new EnvManager($app->basePath('.env'));
        });

        $this->app->singleton(MigrationPlanGenerator::class, function ($app) {
            return new MigrationPlanGenerator($app->storagePath());
        });

        $this->app->singleton(WizardFlow::class, function ($app) {
            return new WizardFlow(
                $app->make(RouteInstaller::class),
                $app->make(AuthSystemInstaller::class),
                $app->make(WebhookInstaller::class),
                $app->make(LaravelWorkosMigrator::class),
                $app->make(EnvManager::class),
                $app->make(MigrationPlanGenerator::class),
            );
        });
    }

    protected function registerSessionManager(): void
    {
        $this->app->singleton(SessionManagerInterface::class, function ($app) {
            $useCookieSession = config('workos.session.cookie_session', true);

            if ($useCookieSession) {
                // Use APP_KEY for cookie decryption - it's guaranteed to exist
                $appKey = config('app.key');
                if (str_starts_with($appKey, 'base64:')) {
                    $appKey = base64_decode(substr($appKey, 7));
                }

                return new CookieSessionManager(
                    $app['request'],
                    $appKey,
                    config('workos.session.cookie_name', 'wos-session')
                );
            }

            return new SessionManager($app['session.store']);
        });

        // Maintain backward compatibility
        $this->app->alias(SessionManagerInterface::class, SessionManager::class);
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
                $app->make(SessionManagerInterface::class),
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
        $router->aliasMiddleware('workos.organization.current', SetCurrentOrganization::class);
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

        Blade::if('impersonating', fn () => $this->app->make(SessionManagerInterface::class)->isImpersonating()
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

        // publishesMigrations() was added in Laravel 11
        // @phpstan-ignore-next-line Laravel 10 compatibility check
        if (version_compare(Application::VERSION, '11.0', '>=')) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'workos-migrations');
        } else {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'workos-migrations');
        }
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
