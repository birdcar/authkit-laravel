<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\AuditLogs\Support\AuditActorResolver;
use Authkit\Authkit\Auth\ApiKeyAuthenticator;
use Authkit\Authkit\Auth\ApiKeyRequestGuard;
use Authkit\Authkit\Auth\JwtClaimsValidator;
use Authkit\Authkit\Auth\SessionRefresher;
use Authkit\Authkit\Auth\WorkosGuard;
use Authkit\Authkit\Authorization\ApiKeyGateHook;
use Authkit\Authkit\Authorization\ClaimsGateHook;
use Authkit\Authkit\Authorization\Listeners\InvalidateFgaCache;
use Authkit\Authkit\Console\Commands\AuthkitCommand;
use Authkit\Authkit\Console\Commands\InspectTokenCommand;
use Authkit\Authkit\Console\Commands\InstallCommand;
use Authkit\Authkit\Console\Commands\MakeWorkosListenerCommand;
use Authkit\Authkit\Console\Commands\WorkCommand;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainUpdated;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Events\Workos\UserDeleted;
use Authkit\Authkit\Events\Workos\UserUpdated;
use Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException;
use Authkit\Authkit\FeatureFlags\WorkosPennantDriver;
use Authkit\Authkit\Filesystem\VaultFilesystemAdapter;
use Authkit\Authkit\Http\Controllers\WorkosWebhookController;
use Authkit\Authkit\Http\JwksGraceCache;
use Authkit\Authkit\Http\Middleware\AuthenticateMcpToken;
use Authkit\Authkit\Http\Middleware\RefreshWorkosSession;
use Authkit\Authkit\Http\Middleware\RequireOrganizationContext;
use Authkit\Authkit\Http\Middleware\VerifyWorkosWebhookSignature;
use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Listeners\UpdateOrganizationDomainVerificationState;
use Authkit\Authkit\Listeners\UpsertOrganizationAndMembershipFromLogin;
use Authkit\Authkit\Listeners\Workos\DeleteOrganizationDomainProjection;
use Authkit\Authkit\Listeners\Workos\DeleteOrganizationMembershipProjection;
use Authkit\Authkit\Listeners\Workos\DeleteOrganizationProjection;
use Authkit\Authkit\Listeners\Workos\DeleteUserProjection;
use Authkit\Authkit\Listeners\Workos\UpsertOrganizationDomainProjection;
use Authkit\Authkit\Listeners\Workos\UpsertOrganizationMembershipProjection;
use Authkit\Authkit\Listeners\Workos\UpsertOrganizationProjection;
use Authkit\Authkit\Listeners\Workos\UpsertUserProjection;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Authkit\Authkit\Organizations\MembershipProjectionResolver;
use Authkit\Authkit\Support\AuthkitConfig;
use Authkit\Authkit\Support\Jwt\JwksVerifier;
use Authkit\Authkit\Support\WorkosClientManager;
use Authkit\Authkit\Vault\DefaultVaultKeyContextResolver;
use Authkit\Authkit\Vault\ResolvesVaultKeyContext;
use Authkit\Authkit\Vault\VaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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

        // Default guard entry so `Auth::guard('authkit-key')` / `auth:authkit-key`
        // work without the consumer hand-editing config/auth.php — without it,
        // AuthManager::resolve() throws "Auth guard [authkit-key] is not
        // defined.". Merged so a consumer's own entry wins (theirs is loaded
        // from config/auth.php before any provider registers).
        $config = $this->app->make(Repository::class);
        $existingGuard = $config->get('auth.guards.authkit-key', []);

        $config->set('auth.guards.authkit-key', array_merge(
            ['driver' => 'authkit-key'],
            is_array($existingGuard) ? $existingGuard : [],
        ));

        // Same treatment for the workos session guard: `authkit:install`
        // deliberately does not edit config/auth.php, and Phase 1's contract
        // promise is "registers routes + guard" — without this default entry
        // a consumer running only the installer hits "Auth guard [workos] is
        // not defined." on their first `auth:workos` route (the gap progress.md
        // tracked into the acceptance phase). Defaults to the stock `users`
        // provider; a consumer's own config/auth.php entry wins the merge.
        $existingWorkosGuard = $config->get('auth.guards.workos', []);

        $config->set('auth.guards.workos', array_merge(
            ['driver' => 'workos', 'provider' => 'users'],
            is_array($existingWorkosGuard) ? $existingWorkosGuard : [],
        ));

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

        // Holds the client manager, so bound (not the spec snippet's
        // singleton) for the same reason as VaultCrypto/VaultManager below: a
        // singleton would pin the pre-swap manager when the MockHandler test
        // harness forgets the manager instance mid-test.
        $this->app->bind(AuditLogManager::class);

        // Stateless — reads the workos guard and request at resolve() time.
        $this->app->singleton(AuditActorResolver::class);

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

        // Fail fast with an exception naming the config key — a bad class name
        // would otherwise surface as a raw BindingResolutionException with no
        // mention of authkit.vault.key_context_resolver, deep inside the first
        // encrypt call (spec-phase-9 §6/§8 "Config missing" failure mode).
        $this->app->bind(ResolvesVaultKeyContext::class, function (Container $app): ResolvesVaultKeyContext {
            $resolverClass = $app->make(Repository::class)->get(
                'authkit.vault.key_context_resolver',
                DefaultVaultKeyContextResolver::class,
            );

            if (! is_string($resolverClass)) {
                throw InvalidVaultKeyContextResolverException::forConfiguredClass(get_debug_type($resolverClass));
            }

            try {
                $resolver = $app->make($resolverClass);
            } catch (BindingResolutionException $e) {
                throw InvalidVaultKeyContextResolverException::forConfiguredClass($resolverClass, $e);
            }

            if (! $resolver instanceof ResolvesVaultKeyContext) {
                throw InvalidVaultKeyContextResolverException::forConfiguredClass($resolverClass);
            }

            return $resolver;
        });

        // Bound (not singleton, despite the spec's snippet) for the same reason
        // as UserManagement/SessionManager above: both hold the client manager,
        // and a singleton would pin the pre-swap manager when the MockHandler
        // test harness forgets the manager instance mid-test.
        $this->app->bind(VaultCrypto::class);
        $this->app->bind(VaultManager::class);

        // Shared URL-parameterized JWKS verification (the MCP resource-server
        // JWKS lives on a different host/path than the session JWKS). Bound —
        // not singleton — so the MockHandler harness's mid-test HandlerStack
        // swap is honored, same rationale as the client bindings above.
        $this->app->bind(JwksVerifier::class, function (Container $app): JwksVerifier {
            return new JwksVerifier(
                $app->make(CacheRepository::class),
                $app->bound(HandlerStack::class) ? $app->make(HandlerStack::class) : null,
            );
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

        $this->registerApiKeyGuard();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('authkit.session', RefreshWorkosSession::class);
        $router->aliasMiddleware('authkit.org', RequireOrganizationContext::class);
        $router->aliasMiddleware('authkit.webhook', VerifyWorkosWebhookSignature::class);
        $router->aliasMiddleware('authkit.mcp', AuthenticateMcpToken::class);

        $this->registerWebhookRouteMacro($router);

        // Deliberately not tied to authkit.org: a route that wants to display
        // the current org (or its absence) without requiring one must be able
        // to call this anywhere.
        Request::macro('organization', function (): ?Model {
            return app(CurrentOrganizationResolver::class)->resolve();
        });

        Event::listen(Login::class, UpsertOrganizationAndMembershipFromLogin::class);

        $this->registerProjectionRefreshListeners();

        $this->registerFgaCacheInvalidationListeners();

        // RBAC from JWT claims, zero HTTP per check. The hook returns true or
        // null only — a non-null before-result short-circuits every policy, so
        // false here would be a global deny (spec-phase-5 Failure Mode 1).
        Gate::before($this->app->make(ClaimsGateHook::class));

        // API-key permissions into Gate. Registered after ClaimsGateHook for
        // reading order only: the two hooks read mutually exclusive permission
        // sources (JWT claims vs key permissions, populated by different
        // guards), so order never changes an outcome (spec-phase-8 §3.4).
        Gate::before($this->app->make(ApiKeyGateHook::class));

        // The sealed cookie is already AEAD-sealed by WorkOS, so Laravel's cookie
        // encryption adds nothing — but it does make the guard's behavior depend on
        // whether a route group runs EncryptCookies, silently yielding guest on
        // `api` routes. Excluding it keeps the guard and the authkit.session
        // middleware usable in any group, and keeps the max_cookie_bytes check
        // measuring the bytes the browser actually receives.
        EncryptCookies::except(SessionCookie::name());

        $this->registerFeatureFlagsDriver();

        // Deliberately BEFORE the console early-return: queue workers and
        // console commands are exactly where a background job would write to a
        // vault disk — registering the driver only for HTTP contexts would
        // silently break console usage.
        $this->registerVaultFilesystemDriver();

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
            MakeWorkosListenerCommand::class,
            WorkCommand::class,
        ]);
    }

    /**
     * Route::workosWebhooks($uri) — one line for an app to receive WorkOS
     * webhooks behind signature verification. CSRF is excluded in the macro
     * itself (not left for the app to remember): a webhook POST carries no
     * browser session or CSRF token, so inside a `web`-grouped routes file it
     * would otherwise 419 before ever reaching signature verification.
     */
    private function registerWebhookRouteMacro(Router $router): void
    {
        $router->macro('workosWebhooks', function (string $uri = 'workos/webhooks') use ($router): Route {
            // String literals filtered by class_exists, not ::class constants:
            // this package supports Laravel 12 AND 13, whose `web` groups apply
            // different CSRF middleware classes. Laravel 13 applies
            // PreventRequestForgery (ValidateCsrfToken is its @deprecated
            // subclass, and withoutMiddleware() only drops an applied class
            // that matches exactly or is a SUBCLASS of the excluded one — so
            // excluding only the deprecated child would silently exclude
            // nothing). Laravel 12 has no PreventRequestForgery at all and
            // applies ValidateCsrfToken. Excluding whichever of the two exists
            // covers both support lanes.
            $csrfMiddleware = array_values(array_filter([
                'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
                'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
            ], 'class_exists'));

            return $router->post($uri, WorkosWebhookController::class)
                ->middleware('authkit.webhook')
                ->withoutMiddleware($csrfMiddleware)
                ->name('authkit.webhooks');
        });
    }

    /**
     * The events pipeline's fan-out: both transports (the authkit:work poller
     * and verified webhooks) dispatch the same typed events, and these eight
     * package-registered listeners keep the declared projections fresh with
     * zero app code. All eight are idempotent — WorkOS delivery is
     * at-least-once, so a replayed batch must rewrite the same rows, never
     * duplicate them.
     */
    private function registerProjectionRefreshListeners(): void
    {
        Event::listen([UserCreated::class, UserUpdated::class], UpsertUserProjection::class);
        Event::listen(UserDeleted::class, DeleteUserProjection::class);
        Event::listen([OrganizationCreated::class, OrganizationUpdated::class], UpsertOrganizationProjection::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationProjection::class);
        Event::listen([
            OrganizationDomainCreated::class,
            OrganizationDomainUpdated::class,
        ], UpsertOrganizationDomainProjection::class);
        Event::listen(OrganizationDomainDeleted::class, DeleteOrganizationDomainProjection::class);

        // Verification outcomes get a dedicated listener that knows the event
        // semantics (state stamping, token clearing, warn-and-no-op on unknown
        // rows) instead of the generic present-keys-only upsert above — the
        // verification_failed payload carries no top-level state at all.
        Event::listen(
            OrganizationDomainVerified::class,
            [UpdateOrganizationDomainVerificationState::class, 'handleVerified'],
        );
        Event::listen(
            OrganizationDomainVerificationFailed::class,
            [UpdateOrganizationDomainVerificationState::class, 'handleVerificationFailed'],
        );
        Event::listen([OrganizationMembershipCreated::class, OrganizationMembershipUpdated::class], UpsertOrganizationMembershipProjection::class);
        Event::listen(OrganizationMembershipDeleted::class, DeleteOrganizationMembershipProjection::class);
    }

    /**
     * The FGA check cache's events-driven invalidation: both sidecar channels
     * (typed membership events, plus the generic fallback carrying the
     * role/permission/group types outside the bounded typed set) bump the
     * cache generation counter. Registered unconditionally rather than behind
     * the authkit.fga.cache.enabled flag: FgaChecker::forgetCache() is itself
     * a config-guarded no-op while the cache is disabled, and gating the
     * registration at boot would pin the flag's boot-time value — a runtime
     * config()->set (tests, tinker) would silently detach invalidation from
     * an enabled cache, which is exactly the stale-decision failure the
     * opt-in design exists to prevent.
     */
    private function registerFgaCacheInvalidationListeners(): void
    {
        Event::listen(
            [OrganizationMembershipCreated::class, OrganizationMembershipUpdated::class, OrganizationMembershipDeleted::class],
            [InvalidateFgaCache::class, 'handleMembershipEvent'],
        );
        Event::listen(GenericWorkosEvent::class, [InvalidateFgaCache::class, 'handleGenericEvent']);
    }

    private function registerVaultFilesystemDriver(): void
    {
        // Storage::extend() rebinds the callback's $this *and its class scope*
        // to the FilesystemManager (RebindsCallbacksToSelf) — same trap as
        // Auth::extend() in registerGuard() below, so the closure must not
        // touch $this or self::.
        Storage::extend('vault', function (Container $app, array $config): VaultFilesystemAdapter {
            $diskName = $config['disk'] ?? null;

            if (! is_string($diskName) || $diskName === '') {
                throw new InvalidArgumentException(
                    "The 'vault' filesystem driver requires a 'disk' key naming the underlying disk to wrap.",
                );
            }

            // The static per-disk key context (the SDK wants
            // array<string, string>); defaults to ['disk' => name].
            $context = [];

            if (array_key_exists('context', $config) && is_array($config['context'])) {
                foreach ($config['context'] as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $context[$key] = $value;
                    }
                }
            } else {
                $context = ['disk' => $diskName];
            }

            $maxEncryptBytes = $config['max_encrypt_bytes']
                ?? $app->make(Repository::class)->get('authkit.vault.filesystem.max_encrypt_bytes', 10 * 1024 * 1024);

            return new VaultFilesystemAdapter(
                inner: Storage::disk($diskName),
                crypto: $app->make(VaultCrypto::class),
                context: $context,
                maxEncryptBytes: (int) $maxEncryptBytes,
            );
        });
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

    /**
     * The stateless API-key guard. This mirrors AuthManager::viaRequest()'s
     * paved path exactly — same RequestGuard machinery, same request refresh —
     * except the guard is the package's ApiKeyRequestGuard subclass, whose
     * setRequest() clears the memoized user (viaRequest's stock RequestGuard
     * would hand request #2 in the same process request #1's principal).
     */
    private function registerApiKeyGuard(): void
    {
        // Same rebinding trap as registerGuard() below: Auth::extend() rebinds
        // the callback's $this to the AuthManager, so the provider's container
        // is captured explicitly.
        $container = $this->app;

        Auth::extend('authkit-key', function (Container $app, string $name, array $config) use ($container): ApiKeyRequestGuard {
            $providerName = $config['provider'] ?? null;

            $guard = new ApiKeyRequestGuard(
                // The authenticator is made per invocation, not captured at
                // boot: it holds the WorkosClientManager, whose singleton the
                // MockHandler test harness forgets mid-test — a boot-time
                // instance would pin the pre-fake client and never see the
                // swapped handler stack.
                function (Request $request) use ($container): ?Authenticatable {
                    $authenticator = $container->make(ApiKeyAuthenticator::class);

                    return $authenticator($request);
                },
                $app->make('request'),
                Auth::createUserProvider(is_string($providerName) ? $providerName : null),
            );

            $container->refresh('request', $guard, 'setRequest');

            return $guard;
        });
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
