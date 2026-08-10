<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Auth\JwtClaimsValidator;
use Authkit\Authkit\Auth\SessionRefresher;
use Authkit\Authkit\Auth\WorkosGuard;
use Authkit\Authkit\Authorization\ClaimsGateHook;
use Authkit\Authkit\Console\Commands\AuthkitCommand;
use Authkit\Authkit\Console\Commands\InspectTokenCommand;
use Authkit\Authkit\Console\Commands\InstallCommand;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\FeatureFlags\WorkosPennantDriver;
use Authkit\Authkit\Http\JwksGraceCache;
use Authkit\Authkit\Http\Middleware\RefreshWorkosSession;
use Authkit\Authkit\Http\Middleware\RequireOrganizationContext;
use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Listeners\UpsertOrganizationAndMembershipFromLogin;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Authkit\Authkit\Organizations\MembershipProjectionResolver;
use Authkit\Authkit\Support\AuthkitConfig;
use Authkit\Authkit\Support\WorkosClientManager;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use RuntimeException;
use WorkOS\PKCEHelper;
use WorkOS\Service\UserManagement;
use WorkOS\SessionManager;

class AuthkitServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/authkit.php', 'authkit');

        $this->app->singleton(Authkit::class);

        // The SDK's HttpClient hands this straight to Guzzle and then keeps the
        // client private, so binding the stack here is the only way to get the
        // JWKS grace middleware in front of the SDK's own requests. bindIf keeps
        // the MockHandler test harness (which binds an instance) authoritative.
        $this->app->bindIf(HandlerStack::class, function (Container $app): HandlerStack {
            $stack = HandlerStack::create();
            $stack->push($app->make(JwksGraceCache::class)->middleware());

            return $stack;
        });

        $this->app->bind(JwksGraceCache::class, function (Container $app): JwksGraceCache {
            return new JwksGraceCache(
                $app->make(CacheRepository::class),
                (int) $app->make(Repository::class)->get('authkit.session.jwks_grace_ttl_seconds', 86400),
            );
        });

        $this->app->singleton(WorkosClientManagerContract::class, function (Container $app): WorkosClientManager {
            return WorkosClientManager::fromConfig(
                $app->make(Repository::class),
                $app->bound(HandlerStack::class) ? $app->make(HandlerStack::class) : null,
            );
        });

        // Derived from the one client rather than constructing a second one. Bound
        // (not singleton) so swapping the handler stack mid-test is picked up.
        $this->app->bind(UserManagement::class, fn (Container $app): UserManagement => $app->make(WorkosClientManagerContract::class)->client()->userManagement());
        $this->app->bind(SessionManager::class, fn (Container $app): SessionManager => $app->make(WorkosClientManagerContract::class)->client()->sessionManager());
        $this->app->bind(PKCEHelper::class, fn (Container $app): PKCEHelper => $app->make(WorkosClientManagerContract::class)->client()->pkce());

        $this->app->singleton(JwtClaimsValidator::class, function (Container $app): JwtClaimsValidator {
            $config = $app->make(Repository::class);
            $issuer = $config->get('authkit.jwt.issuer');
            $audience = $config->get('authkit.jwt.audience');

            return new JwtClaimsValidator(
                expectedIssuer: is_string($issuer) && $issuer !== '' ? $issuer : null,
                expectedAudience: is_string($audience) && $audience !== ''
                    ? $audience
                    : AuthkitConfig::clientId(),
            );
        });

        $this->app->bind(SessionRefresher::class, function (Container $app): SessionRefresher {
            $config = $app->make(Repository::class);

            return new SessionRefresher(
                $app->make(SessionManager::class),
                (int) $config->get('authkit.session.lock_ttl_seconds', 10),
                (int) $config->get('authkit.session.lock_wait_seconds', 5),
            );
        });

        // Singleton: request-memoized — repeated $request->organization() /
        // Authkit::currentOrganization() calls cost one query per request.
        $this->app->singleton(CurrentOrganizationResolver::class);

        // bindIf so an app (or a later phase) can override without a container
        // conflict; the concrete class comes from config so swapping resolvers
        // is a config change, not a provider edit.
        $this->app->bindIf(ResolvesOrganizationMembershipId::class, function (Container $app): ResolvesOrganizationMembershipId {
            $resolver = $app->make(Repository::class)->get(
                'authkit.authorization.membership_resolver',
                MembershipProjectionResolver::class,
            );

            $instance = $app->make(is_string($resolver) ? $resolver : MembershipProjectionResolver::class);

            if (! $instance instanceof ResolvesOrganizationMembershipId) {
                throw new RuntimeException(
                    'The [authkit.authorization.membership_resolver] config value must name a class implementing '
                    .ResolvesOrganizationMembershipId::class.'.',
                );
            }

            return $instance;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/authkit-laravel.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'authkit-laravel');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'authkit-laravel');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerGuard();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('authkit.session', RefreshWorkosSession::class);
        $router->aliasMiddleware('authkit.org', RequireOrganizationContext::class);

        // Deliberately not tied to authkit.org: a route that wants to display
        // the current org (or its absence) without requiring one must be able
        // to call this anywhere.
        Request::macro('organization', function (): ?Model {
            return app(CurrentOrganizationResolver::class)->resolve();
        });

        Event::listen(Login::class, UpsertOrganizationAndMembershipFromLogin::class);

        // RBAC from JWT claims, zero HTTP per check. The hook returns true or
        // null only — a non-null before-result short-circuits every policy, so
        // false here would be a global deny (spec-phase-5 Failure Mode 1).
        Gate::before($this->app->make(ClaimsGateHook::class));

        // The sealed cookie is already AEAD-sealed by WorkOS, so Laravel's cookie
        // encryption adds nothing — but it does make the guard's behavior depend on
        // whether a route group runs EncryptCookies, silently yielding guest on
        // `api` routes. Excluding it keeps the guard and the authkit.session
        // middleware usable in any group, and keeps the max_cookie_bytes check
        // measuring the bytes the browser actually receives.
        EncryptCookies::except(SessionCookie::name());

        $this->registerFeatureFlagsDriver();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/authkit.php' => config_path('authkit.php'),
        ], ['authkit-laravel', 'authkit-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['authkit-laravel', 'authkit-laravel-migrations']);

        $this->commands([
            AuthkitCommand::class,
            InstallCommand::class,
            InspectTokenCommand::class,
        ]);
    }

    private function registerFeatureFlagsDriver(): void
    {
        $config = $this->app->make(Repository::class);

        // Dot-notation nested set — only touches the "workos" leaf, never the
        // sibling "array"/"database" store entries laravel/pennant's own
        // mergeConfigFrom adds in ITS register(). Pennant's merge is a shallow
        // array_merge at the top-level "pennant" key, so seeding our entry in
        // register() before Pennant's could silently drop the built-in stores;
        // every register() finishes before any boot() runs, which makes this
        // ordering-safe regardless of package discovery order (spec-phase-7
        // Decision D-4).
        $existing = $config->get('pennant.stores.workos', []);

        $config->set('pennant.stores.workos', array_merge(
            ['driver' => 'workos'],
            is_array($existing) ? $existing : [],
        ));

        Feature::extend('workos', function (Container $container, array $storeConfig): WorkosPennantDriver {
            return new WorkosPennantDriver(
                $container->make(WorkosClientManagerContract::class),
                $container->make(CacheRepository::class),
                (int) $container->make(Repository::class)->get('authkit.feature_flags.cache_ttl', 30),
            );
        });

        // Default scope = the WorkOS-authenticated user, not the app's ambient
        // default guard — this package must not assume the install rewired
        // auth.defaults.guard. An app-level provider booting after this one may
        // call Feature::resolveScopeUsing() again and win (spec-phase-7
        // Decision D-5).
        Feature::resolveScopeUsing(fn (): ?Authenticatable => Auth::guard('workos')->user());
    }

    private function registerGuard(): void
    {
        // AuthManager::extend() rebinds the callback's $this *and its class scope*
        // to the AuthManager (RebindsCallbacksToSelf), so `self::` and `$this`
        // inside the closure would resolve against AuthManager, not this provider.
        // Everything the closure needs is captured here instead.
        $container = $this->app;

        Auth::extend('workos', function (Container $app, string $name, array $config) use ($container): WorkosGuard {
            $configRepository = $app->make(Repository::class);
            $providerName = $config['provider'] ?? null;
            $provider = Auth::createUserProvider(is_string($providerName) ? $providerName : null);

            if (! $provider instanceof UserProvider) {
                throw new RuntimeException("The [{$name}] guard has no user provider configured. Set auth.guards.{$name}.provider in config/auth.php.");
            }

            $guard = new WorkosGuard(
                provider: $provider,
                sessionManager: $app->make(SessionManager::class),
                validator: $app->make(JwtClaimsValidator::class),
                request: $app->make('request'),
                cookieName: (string) $configRepository->get('authkit.session.cookie', 'authkit_session'),
                cookiePassword: AuthkitConfig::cookiePassword(),
                clientId: AuthkitConfig::clientId(),
                baseUrl: AuthkitConfig::baseUrl(),
            );

            // AuthManager memoizes guards and, unlike its own session/token drivers,
            // never rebinds the request for a custom creator — so a second request in
            // the same process would otherwise read the first request's cookies.
            $container->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
