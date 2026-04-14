<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use WorkOS\AuditLogs;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Audit\AuditMiddleware;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSGuard;
use WorkOS\AuthKit\Commands\EventsListenCommand;
use WorkOS\AuthKit\Commands\InstallCommand;
use WorkOS\AuthKit\Commands\MakeListenerCommand;
use WorkOS\AuthKit\Commands\SyncUsersCommand;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
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
use WorkOS\AuthKit\Listeners\SyncMembershipFromWorkOS;
use WorkOS\AuthKit\Listeners\SyncOrganizationFromWorkOS;
use WorkOS\AuthKit\Listeners\SyncUserFromWorkOS;
use WorkOS\AuthKit\Livewire\Widgets\AdminPortal\AdminPortal;
use WorkOS\AuthKit\Livewire\Widgets\AdminPortal\DomainList;
use WorkOS\AuthKit\Livewire\Widgets\AdminPortal\SsoConnectionList;
use WorkOS\AuthKit\Livewire\Widgets\ApiKeys\ApiKeyList;
use WorkOS\AuthKit\Livewire\Widgets\ApiKeys\ApiKeys;
use WorkOS\AuthKit\Livewire\Widgets\DataIntegrations\DataIntegrationList;
use WorkOS\AuthKit\Livewire\Widgets\DataIntegrations\DataIntegrations;
use WorkOS\AuthKit\Livewire\Widgets\DirectorySync\DirectoryList;
use WorkOS\AuthKit\Livewire\Widgets\DirectorySync\DirectorySync;
use WorkOS\AuthKit\Livewire\Widgets\Settings\OrganizationSettings;
use WorkOS\AuthKit\Livewire\Widgets\Settings\Settings;
use WorkOS\AuthKit\Livewire\Widgets\UserManagement\InviteUser;
use WorkOS\AuthKit\Livewire\Widgets\UserManagement\MemberActions;
use WorkOS\AuthKit\Livewire\Widgets\UserManagement\MembersTable;
use WorkOS\AuthKit\Livewire\Widgets\UserManagement\UserManagement;
use WorkOS\AuthKit\Livewire\Widgets\UserProfile\ProfileInfo;
use WorkOS\AuthKit\Livewire\Widgets\UserProfile\SecuritySettings;
use WorkOS\AuthKit\Livewire\Widgets\UserProfile\SessionManagement;
use WorkOS\AuthKit\Livewire\Widgets\UserProfile\UserProfile;
use WorkOS\AuthKit\Support\EnvironmentDetector;
use WorkOS\AuthKit\Support\EventRouting;

class WorkOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workos.php', 'workos');

        $this->registerSessionManager();

        $this->app->singleton('workos', function ($app) {
            $this->configureWorkOSSdk();

            return new WorkOS($app->make(SessionManager::class));
        });

        $this->app->alias('workos', WorkOS::class);

        $this->app->singleton(\WorkOS\AuthKit\FeatureFlags\FeatureFlagService::class, function ($app) {
            return new \WorkOS\AuthKit\FeatureFlags\FeatureFlagService(
                $app->make(SessionManager::class),
                new \WorkOS\Organizations,
            );
        });

        $this->app->singleton(\WorkOS\AuthKit\FGA\FGAService::class, function ($app) {
            return new \WorkOS\AuthKit\FGA\FGAService(
                $app->make(SessionManager::class),
            );
        });

        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                new AuditLogs,
                $app->make(SessionManager::class)
            );
        });

        $this->app->singleton(EnvironmentDetector::class, function ($app) {
            return new EnvironmentDetector(
                $app->make('files'),
                $app->basePath()
            );
        });

        $this->app->singleton(EventRouting::class, function () {
            /** @var array<string, string> $categories */
            $categories = config('workos.events.routing.categories', []);
            /** @var array<string, string> $overrides */
            $overrides = config('workos.events.routing.overrides', []);

            return new EventRouting(categories: $categories, overrides: $overrides);
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
        $this->app->singleton(SessionManager::class, function () {
            $appKey = config('app.key');
            if (str_starts_with($appKey, 'base64:')) {
                $appKey = base64_decode(substr($appKey, 7));
            }

            return new SessionManager(
                $appKey,
                config('workos.session.cookie_name', 'wos-session')
            );
        });
    }

    public function boot(): void
    {
        $this->configureCookieEncryption();
        $this->configureGuard();
        $this->configureMiddleware();
        $this->configureBladeDirectives();
        $this->configureMigrations();
        $this->configurePublishing();
        $this->configureRoutes();
        $this->configureWebhooks();
        $this->configureEventListeners();
        $this->configureCommands();
        $this->configureFGA();
        $this->configureLivewireWidgets();
    }

    /**
     * Exclude the WorkOS session cookie from Laravel's cookie encryption.
     *
     * The wos-session cookie is already encrypted using WorkOS's Halite-based
     * encryption, so Laravel's EncryptCookies middleware must not double-encrypt it.
     */
    protected function configureCookieEncryption(): void
    {
        $cookieName = config('workos.session.cookie_name', 'wos-session');
        EncryptCookies::except($cookieName);
    }

    protected function configureWorkOSSdk(): void
    {
        $config = config('workos');

        $apiKey = $config['api_key'] ?? null;
        $clientId = $config['client_id'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw Exceptions\WorkOSException::missingConfiguration('WORKOS_API_KEY');
        }

        if (! is_string($clientId) || $clientId === '') {
            throw Exceptions\WorkOSException::missingConfiguration('WORKOS_CLIENT_ID');
        }

        \WorkOS\WorkOS::setApiKey($apiKey);
        \WorkOS\WorkOS::setClientId($clientId);
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

        Auth::provider('workos-apikey', fn () => new \WorkOS\AuthKit\Auth\ApiKeyUserProvider);
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
        $router->aliasMiddleware('workos.apikey', \WorkOS\AuthKit\Http\Middleware\ValidateApiKey::class);
        $router->aliasMiddleware('workos.feature', \WorkOS\AuthKit\Http\Middleware\CheckFeatureFlag::class);
        $router->aliasMiddleware('workos.radar', \WorkOS\AuthKit\Http\Middleware\ReportRadarAttempt::class);
        $router->aliasMiddleware('workos.inertia', ShareWorkOSData::class);
    }

    protected function configureBladeDirectives(): void
    {
        Blade::if('workosRole', function (string $role) {
            /** @var Guard $guard */
            $guard = auth();
            $user = $guard->user();

            if ($user && method_exists($user, 'hasWorkOSRole')) {
                return $user->hasWorkOSRole($role);
            }

            return false;
        });

        Blade::if('workosPermission', function (string $permission) {
            /** @var Guard $guard */
            $guard = auth();
            $user = $guard->user();

            if ($user && method_exists($user, 'hasWorkOSPermission')) {
                return $user->hasWorkOSPermission($permission);
            }

            return false;
        });

        Blade::if('impersonating', fn () => $this->app->make(SessionManager::class)->isImpersonating()
        );

        Blade::if('workosFeature', fn (string $flag) => $this->app->make(\WorkOS\AuthKit\FeatureFlags\FeatureFlagService::class)->isEnabled($flag));

        Blade::if('workosEntitlement', fn (string $entitlement) => $this->app->make('workos')->hasEntitlement($entitlement));
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

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
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
        $defaults = [
            WorkOSUserCreated::class => [SyncUserFromWorkOS::class, 'handle'],
            WorkOSUserUpdated::class => [SyncUserFromWorkOS::class, 'handle'],
            WorkOSOrganizationCreated::class => [SyncOrganizationFromWorkOS::class, 'handle'],
            WorkOSOrganizationUpdated::class => [SyncOrganizationFromWorkOS::class, 'handle'],
            WorkOSMembershipCreated::class => [SyncMembershipFromWorkOS::class, 'handleCreated'],
            WorkOSMembershipUpdated::class => [SyncMembershipFromWorkOS::class, 'handleUpdated'],
            WorkOSMembershipDeleted::class => [SyncMembershipFromWorkOS::class, 'handleDeleted'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserCreated::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryUserFromWorkOS::class, 'handleCreatedOrUpdated'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserUpdated::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryUserFromWorkOS::class, 'handleCreatedOrUpdated'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserDeleted::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryUserFromWorkOS::class, 'handleDeleted'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupCreated::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryGroupFromWorkOS::class, 'handleCreatedOrUpdated'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUpdated::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryGroupFromWorkOS::class, 'handleCreatedOrUpdated'],
            \WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupDeleted::class => [\WorkOS\AuthKit\Listeners\SyncDirectoryGroupFromWorkOS::class, 'handleDeleted'],
        ];

        /** @var array<class-string, class-string|null> $overrides */
        $overrides = config('workos.sync.listeners', []);

        foreach ($defaults as $event => $defaultListener) {
            if (array_key_exists($event, $overrides)) {
                $override = $overrides[$event];
                if ($override !== null) {
                    Event::listen($event, $override);
                }

                continue;
            }

            Event::listen($event, $defaultListener);
        }
    }

    protected function configureCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            MakeListenerCommand::class,
            SyncUsersCommand::class,
            EventsListenCommand::class,
            \WorkOS\AuthKit\Commands\FGACheckCommand::class,
        ]);
    }

    protected function configureFGA(): void
    {
        if (! config('workos.fga.enabled', false)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('workos.fga', \WorkOS\AuthKit\Http\Middleware\CheckFGAAccess::class);

        Blade::if('workosAccess', function (string $permission, \WorkOS\AuthKit\FGA\FGAResource $resource) {
            return app(\WorkOS\AuthKit\FGA\FGAService::class)->checkForCurrentUser(
                permission: $permission,
                resourceType: $resource->resourceType,
                resourceId: $resource->resourceId,
            );
        });

        if (config('workos.fga.gate_integration', false)) {
            Gate::after(function (\Illuminate\Contracts\Auth\Authenticatable $user, string $ability, ?bool $result, mixed $arguments) {
                if ($result !== null) {
                    return $result;
                }

                $resource = $arguments[0] ?? null;
                if (! $resource instanceof \WorkOS\AuthKit\FGA\FGAResource) {
                    return null;
                }

                $userId = method_exists($user, 'getWorkOSId') ? $user->getWorkOSId() : (string) $user->getAuthIdentifier();

                return app(\WorkOS\AuthKit\FGA\FGAService::class)->check(
                    userId: $userId,
                    permission: $ability,
                    resourceType: $resource->resourceType,
                    resourceId: $resource->resourceId,
                );
            });
        }
    }

    protected function configureLivewireWidgets(): void
    {
        if (! class_exists(Component::class)) {
            return;
        }

        if (! config('workos.features.widgets', true)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'workos');

        $this->publishes([
            __DIR__.'/../resources/views/livewire/widgets' => resource_path('views/vendor/workos/livewire/widgets'),
        ], 'workos-widget-views');

        $this->publishes([
            __DIR__.'/../resources/css/widgets.css' => public_path('vendor/workos/widgets.css'),
        ], 'workos-widget-styles');

        $livewire = 'Livewire\Livewire';
        $livewire::component('workos-members-table', MembersTable::class);
        $livewire::component('workos-member-actions', MemberActions::class);
        $livewire::component('workos-invite-user', InviteUser::class);
        $livewire::component('workos-user-management', UserManagement::class);
        $livewire::component('workos-profile-info', ProfileInfo::class);
        $livewire::component('workos-security-settings', SecuritySettings::class);
        $livewire::component('workos-session-management', SessionManagement::class);
        $livewire::component('workos-user-profile', UserProfile::class);
        $livewire::component('workos-sso-connection-list', SsoConnectionList::class);
        $livewire::component('workos-domain-list', DomainList::class);
        $livewire::component('workos-admin-portal', AdminPortal::class);
        $livewire::component('workos-api-key-list', ApiKeyList::class);
        $livewire::component('workos-data-integration-list', DataIntegrationList::class);
        $livewire::component('workos-directory-list', DirectoryList::class);
        $livewire::component('workos-organization-settings', OrganizationSettings::class);
        $livewire::component('workos-api-keys', ApiKeys::class);
        $livewire::component('workos-data-integrations', DataIntegrations::class);
        $livewire::component('workos-directory-sync', DirectorySync::class);
        $livewire::component('workos-settings', Settings::class);
    }
}
