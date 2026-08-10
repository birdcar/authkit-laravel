# Implementation Spec: AuthKit Laravel v1 - Phase 1: Foundation & Client Binding

**Contract**: ../authkit-laravel-v1/contract-data.json
**Estimated Effort**: L

## Technical Approach

Phase 1 builds the foundation every later phase constructs on: a config-driven way to obtain a correctly-constructed `WorkOS\WorkOS` SDK client, the full `config/authkit.php` schema every subsystem (auth, orgs, events, flags, vault, MCP, emulate) will read from, an `authkit:install` command that gets a fresh app from `composer require` to a configured state, the test-harness plumbing (`workos/emulate` process management + Guzzle `MockHandler` wiring) every later phase's tests depend on, and a dev-only `authkit:inspect-token` command whose whole purpose is to empirically resolve one specific unknown: the vendored SDK's `SessionManager` does not verify a JWT's `iss`/`aud` claims (confirmed by reading `SessionManager.php`'s `decodeAccessToken`, which has a TODO citing this as an open question upstream) and no other package doc records the canonical values. Phase 2's guard cannot safely enforce `iss`/`aud` without them.

The through-line is "config is the only source of WorkOS credentials, everywhere." `WorkosClientManager` never calls `env()` and always passes explicit, non-null strings into `WorkOS::__construct()` — the SDK's own constructor falls back to `getenv('WORKOS_API_KEY')` when the parameter it receives is PHP `null` (verified by reading `WorkOS.php:91-92`), so "config-only" is not just a style preference, it is the mechanism that keeps `php artisan config:cache` (which empties `env()` at runtime — a documented Laravel footgun, `laravel/framework#55028`) from silently swapping credentials out from under the app. Every config value flows through `Illuminate\Contracts\Config\Repository`, never a raw `env()` call in `src/`.

The existing skeleton scaffold (`config/authkit-laravel.php`, `AuthkitCommand`, placeholder route/view/lang) is a generic package-skeleton starting point, not hand-built for this project. Phase 1 only touches the parts of it that its five named components directly collide with: the config file (renamed/rewritten, since "config/authkit.php ... replacing config/authkit-laravel.php" is explicit scope) and the placeholder migration (deleted, because it would otherwise get published into every consumer app's `database/migrations/` by the new `authkit:install` command for no reason — a nonsense `authkit_laravel_placeholder` table). The placeholder `Authkit` class, `authkit-laravel:placeholder` command, and placeholder route/view/lang are left untouched: nothing in this phase's five named components needs them, and touching them would be unrequested cleanup outside this phase's scope.

## Decisions Considered and Rejected

_Carried from the contract; consult before making gap decisions._

- **Breadth-complete v1: all 16 scope areas ship in the first version at usable-core depth; phases are build order, not releases** — rejected: Release-tiered rollout (v0.1 auth core, features in v0.2+). Dual basis recorded per scope-creep review: ecosystem-substitution logic covers RBAC (spatie), Feature Flags (other Pennant drivers), and API auth (Passport); the remaining areas are MVP by explicit stakeholder decision (Nick: "literally all of the features I listed are our MVP"). _(Relevance to Phase 1: `config/authkit.php` declares keys for every subsystem — events, feature_flags, vault, mcp — now, even though those phases build the behavior later.)_
- **Custom workos guard with the AuthKit sealed session cookie as canonical auth state; app's Laravel session stays free for app state** — rejected: Exchange code then hydrate Laravel's standard session guard (laravel/workos approach). WorkOS must remain the session source of truth for both authn and authz; the SDK's SessionManager already does unseal/refresh/JWKS heavy lifting. _(Relevance: this is why `cookie_password` and `jwt.issuer`/`jwt.audience` are config keys now, even though the guard itself is Phase 2.)_
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage** — rejected: SDK fakes only. Wire fidelity where possible; emulate v0.6.0 covers ~62% of endpoints and the SDK's base-URL override plus injectable Guzzle handler make both paths clean. _(Directly implements this phase's test-harness plumbing.)_
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with workos_id ↔ external_id linking, refreshed by the events pipeline** — rejected: No local state / read-through API calls per request. Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events (confirmed by Nick). _(Relevance: this is why config declares `user.model`/`organization.model` mapping keys now, even though no migration or trait exists yet.)_
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5 (requires PHP 8.4+). PHP 8.3 is officially supported until Dec 2027 and Laravel 13 supports it; dropping it violates the support-matrix requirement. Revisit at 8.3 EOL. Paratest friction on PHP 8.5 handled by non-parallel runs where needed. _(Relevance: the test-harness plumbing must work under `--parallel` and must not assume Pest 5 APIs.)_
- **Credentials read from config only; env is never read outside config files** — rejected: Runtime env() reads like the SDK's own fallback does. `php artisan config:cache` empties env at runtime (laravel/framework#55028 class of bug); config-only is the Laravel paved path. _(This is the core doctrine `WorkosClientManager` and `config/authkit.php` implement.)_
- **Auth flows exposed both as registered routes and as form-request helpers, with routes as thin wrappers delegating to the form requests** — rejected: Routes-only surface. Apps with custom controllers keep every nicety — parity with the one thing laravel/workos got right; one implementation, two entry points. _(Relevance: shapes the `routes.enabled`/`routes.prefix`/`routes.paths` config keys this phase declares, even though Phase 2 wires the actual routes.)_
- **Widgets are excluded from v1 entirely — no token-minting facade** — rejected: Widget token minting in MVP, or demoting it to Full tier. Nick's ruling: widgets are UI surface and the starter kit owns UI. _(Named here to preempt adding a `widgets.*` config key or command — out of scope.)_
- **Phase 1 ends with an empirical AuthKit token audit: decode a real AuthKit-issued token to confirm canonical iss/aud values and default presence of role/permissions/feature_flags claims, recorded in the decision log before Phase 2 starts** — rejected: Assume the SDK's TODO values and default-populated claims. Hidden-dependency blocker: SessionManager's own source defers iss/aud as unconfirmed, and the zero-HTTP RBAC + claim-first flags + quickstart goals all silently depend on claims being present without dashboard setup. _(This is the entire reason the `authkit:inspect-token` component exists.)_
- **Express run executes directly on main (no isolation branch); recovery anchor recorded: git reset --hard e845a2f** — rejected: `ideation/authkit-laravel-v1` isolation branch (express default). Stakeholder choice: nothing auto-pushes, and reset-based recovery is acceptable. _(Operational note for whoever implements this phase: commit directly to `main`, no branch dance; `git reset --hard e845a2f` is the documented rollback anchor for the whole express run, not just this phase.)_

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter="WorkosClientManager|EnvFileUpdater|ConfigCache|InstallIdempotent|InspectTokenCommand"`

**Playground**: Pest test suite only — no dev server, no browser. Phase 1 ships container bindings and console commands, all of which are directly testable through Testbench's `artisan()`/`app()` helpers.

**Why this approach**: every Phase 1 component except the test harness itself is container-resolvable or artisan-invokable in well under a second; the inner-loop filter deliberately excludes `EmulateServerTest` (which spawns a real `npx` process and can take several seconds even when it succeeds) so the fast loop stays fast — run `vendor/bin/pest --filter=EmulateServer` separately when working on the process-management code itself.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `config/authkit.php` | Full v1 config schema (credentials, jwt placeholders, routes, user/org mapping, events, feature flags, vault, mcp, emulate) |
| `src/Contracts/WorkosClientManager.php` | Interface: `client(): \WorkOS\WorkOS` |
| `src/Support/WorkosClientManager.php` | Config-driven, never-env-fallback WorkOS SDK client construction; caches the built client |
| `src/Support/EnvFileUpdater.php` | Pure, path-parameterized `.env`/`.env.example` key-appending logic (idempotent, testable without a container) |
| `src/Console/Commands/InstallCommand.php` | `authkit:install` — publishes config + migrations, appends env keys idempotently, prints next steps |
| `src/Console/Commands/InspectTokenCommand.php` | `authkit:inspect-token` — dev-only decode of a pasted token/sealed session, prints iss/aud/claims |
| `tests/Support/EmulateServer.php` | Boots `npx @workos/emulate`, polls `/health`, exposes `baseUrl()`; availability pre-flight check |
| `tests/Concerns/UsesWorkosMockHandler.php` | Pest trait: binds a fresh `MockHandler`+`HandlerStack`, forces the client manager singleton to rebuild |
| `tests/Fixtures/workos-emulate.config.yaml` | Minimal seed fixture for `EmulateServer` (test-only; not a publishable resource in this phase) |
| `tests/Feature/WorkosClientManagerTest.php` | Config resolution, emulate override, never-triggers-SDK-env-fallback (via captured Authorization header) |
| `tests/Feature/ConfigCacheTest.php` | `config:cache` boots cleanly; container still resolves the client; no `env(` calls in `src/` |
| `tests/Feature/InstallIdempotentTest.php` | `authkit:install` run twice produces byte-identical `.env`, no duplicate config publish |
| `tests/Feature/InspectTokenCommandTest.php` | Raw-JWT path, sealed-session path, malformed input, absent-claim reporting |
| `tests/Feature/EmulateServerTest.php` | Boot/health/stop smoke test; self-skips when `npx`/Node is unavailable |
| `tests/Unit/EnvFileUpdaterTest.php` | Pure unit coverage of idempotent key-appending against scratch tmp files |
| `docs/token-audit.md` | Human-executed procedure for confirming canonical `iss`/`aud` and default claim presence |
| `docs/token-audit-findings.md` | Recorded findings from running the procedure — ships with placeholder `TBD` values |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/AuthkitServiceProvider.php` | `mergeConfigFrom` points at `config/authkit.php` under key `authkit`; config publish tag becomes `authkit-config` (migrations/views/lang/assets tags unchanged); bind `WorkosClientManagerContract` singleton via `WorkosClientManager::fromConfig()`; register `InstallCommand` and `InspectTokenCommand` in `commands()` |
| `composer.json` | Add `guzzlehttp/guzzle: ^7.0\|\|^8.0` and `illuminate/contracts: ^12.0\|\|^13.0` to `require` (both already transitive via `workos/workos-php` and `illuminate/support` respectively; `src/` now type-hints them directly, so make the dependency explicit) |
| `tests/Feature/ExampleTest.php` | Remove the `config('authkit-laravel.placeholder')` assertion (key no longer exists after the rename); replace with an assertion against a real `config('authkit.*')` default (e.g. `base_url`) |

### Deleted Files

| File Path | Reason |
| --- | --- |
| `config/authkit-laravel.php` | Renamed to `config/authkit.php` with the full v1 schema — this is the explicit scope item, not a separate cleanup |
| `database/migrations/2026_01_01_000000_create_authkit_laravel_placeholder_table.php` | Dead scaffold; its table is referenced by nothing. Left in place, `authkit:install`'s migration-publish step would ship a meaningless `authkit_laravel_placeholder` table into every consumer app |

## Implementation Details

### 1. WorkosClientManager & Contract

**Pattern to follow**: `src/AuthkitServiceProvider.php` (existing singleton-binding style); `vendor/workos/workos-php/lib/WorkOS.php` for the exact constructor signature (do not guess it — read it).

**Overview**: A container-bound singleton that is the *only* place in the package allowed to construct a `WorkOS\WorkOS` instance. It resolves every constructor argument from config (never `env()`), resolves the emulate override, and accepts an injectable `GuzzleHttp\HandlerStack` so tests can swap in a `MockHandler` without touching the manager's own code.

```php
namespace Authkit\Authkit\Contracts;

use WorkOS\WorkOS;

interface WorkosClientManager
{
    public function client(): WorkOS;
}
```

```php
namespace Authkit\Authkit\Support;

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Config\Repository;
use WorkOS\WorkOS;

final class WorkosClientManager implements WorkosClientManagerContract
{
    private ?WorkOS $client = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $clientId,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $maxRetries,
        private readonly ?HandlerStack $handler = null,
    ) {
    }

    public static function fromConfig(Repository $config, ?HandlerStack $handler = null): self
    {
        $emulate = (bool) $config->get('authkit.emulate.enabled', false);

        return new self(
            apiKey: (string) ($emulate
                ? $config->get('authkit.emulate.api_key', 'sk_test_default')
                : $config->get('authkit.api_key', '')),
            clientId: (string) $config->get('authkit.client_id', ''),
            baseUrl: (string) ($emulate
                ? $config->get('authkit.emulate.base_url', 'http://localhost:4100')
                : $config->get('authkit.base_url', 'https://api.workos.com')),
            timeout: (int) $config->get('authkit.timeout', 60),
            maxRetries: (int) $config->get('authkit.max_retries', 3),
            handler: $handler,
        );
    }

    public function client(): WorkOS
    {
        // apiKey/clientId are ALWAYS strings, never null — WorkOS::__construct()
        // does `$apiKey ??= getenv('WORKOS_API_KEY')` (WorkOS.php:91-92), which
        // only fires when the argument it receives is literally null. Passing
        // '' instead of null is what keeps the SDK's own env fallback dead.
        return $this->client ??= new WorkOS(
            apiKey: $this->apiKey,
            clientId: $this->clientId,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            maxRetries: $this->maxRetries,
            handler: $this->handler,
        );
    }
}
```

Service provider wiring (in `register()`):

```php
$this->app->singleton(WorkosClientManagerContract::class, function ($app) {
    return WorkosClientManager::fromConfig(
        $app['config'],
        $app->bound(HandlerStack::class) ? $app->make(HandlerStack::class) : null,
    );
});
```

**Key decisions**:

- `fromConfig()` is a static factory taking `Illuminate\Contracts\Config\Repository` directly rather than doing config resolution inline in the service provider closure — keeps the config→SDK-args mapping in one unit-testable place, not smeared across `register()`.
- The built `WorkOS` instance is cached (`??=`) inside the manager. Constructing a fresh `WorkOS` per call would silently reset `SessionManager`'s in-process JWKS cache (5-minute TTL, keyed per-instance) every time — caching the client is what makes that cache actually work.
- Emulate override lives inside `fromConfig()`, not in the service provider — one branch, one place, unit-testable without booting a container.

**Implementation steps**:

1. `mkdir -p src/Contracts src/Support` (plain file creation — no `php artisan make:` generator applies to a package-internal interface/support class; generators are for Eloquent/migrations/etc., used elsewhere in this phase).
2. Write the interface, then the implementation, then wire the singleton binding into `AuthkitServiceProvider::register()`.
3. Add `guzzlehttp/guzzle` and `illuminate/contracts` to `composer.json` `require` (see File Changes) and run `composer update guzzlehttp/guzzle illuminate/contracts --with-all-dependencies` (or full `composer update` if simpler) so the lock file matches.

**Feedback loop**:

- **Playground**: `tests/Feature/WorkosClientManagerTest.php`, run via Testbench (`app(WorkosClientManagerContract::class)`).
- **Experiment**: (a) resolve the client with default config and assert `client()->userManagement()` is reachable (no exception constructing it); (b) `putenv('WORKOS_API_KEY', 'should-never-be-used')`, set `authkit.api_key` to a known value via `config()->set()`, bind a `MockHandler` capturing a `Middleware::history()`, trigger any one lightweight SDK call, and assert the captured request's `Authorization` header is `Bearer <the-config-value>` — never the poisoned env value; (c) set `authkit.emulate.enabled` true and assert the resolved base URL matches `authkit.emulate.base_url`.
- **Check command**: `vendor/bin/pest --filter=WorkosClientManager`

### 2. config/authkit.php Schema

**Pattern to follow**: `config/authkit-laravel.php` (the file being replaced) for style; every key documented in the phase's binding scope.

**Overview**: The single source of every WorkOS-related setting in the package. Every value is a scalar/array literal (no closures, no objects) so `php artisan config:cache`'s `var_export`-based caching works unchanged.

```php
<?php

declare(strict_types=1);

return [

    'api_key' => env('WORKOS_API_KEY', ''),
    'client_id' => env('WORKOS_CLIENT_ID', ''),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', ''),
    'cookie_password' => env('WORKOS_COOKIE_PASSWORD', ''),
    'base_url' => env('WORKOS_BASE_URL', 'https://api.workos.com'),
    'timeout' => (int) env('WORKOS_TIMEOUT', 60),
    'max_retries' => (int) env('WORKOS_MAX_RETRIES', 3),

    // SessionManager does not verify iss/aud (decodeAccessToken TODO). These
    // MUST be replaced with values confirmed by docs/token-audit.md before
    // Phase 2 implements guard-level enforcement — see docs/token-audit-findings.md.
    'jwt' => [
        'issuer' => env('WORKOS_JWT_ISSUER'),
        'audience' => env('WORKOS_JWT_AUDIENCE'),
    ],

    'routes' => [
        'enabled' => (bool) env('AUTHKIT_ROUTES_ENABLED', true),
        'prefix' => env('AUTHKIT_ROUTES_PREFIX', 'authkit'),
        'middleware' => ['web'],
        'paths' => [
            'login' => 'login',
            'logout' => 'logout',
            'callback' => 'callback',
        ],
    ],

    'user' => [
        'model' => env('AUTHKIT_USER_MODEL', \App\Models\User::class),
        'external_id_column' => 'workos_id',
    ],

    'organization' => [
        'model' => env('AUTHKIT_ORGANIZATION_MODEL'),
        'external_id_column' => 'workos_id',
    ],

    'events' => [
        'enabled' => (bool) env('AUTHKIT_EVENTS_ENABLED', true),
        'poll_interval' => (int) env('AUTHKIT_EVENTS_POLL_INTERVAL', 5),
        'cursor_cache_store' => env('AUTHKIT_EVENTS_CURSOR_STORE'),
    ],

    'feature_flags' => [
        'cache_ttl' => (int) env('AUTHKIT_FEATURE_FLAGS_CACHE_TTL', 30),
    ],

    'vault' => [
        'key_context' => env('AUTHKIT_VAULT_KEY_CONTEXT'),
    ],

    'mcp' => [
        'resource_indicator' => env('AUTHKIT_MCP_RESOURCE_INDICATOR'),
    ],

    'emulate' => [
        'enabled' => (bool) env('AUTHKIT_EMULATE_ENABLED', false),
        'base_url' => env('AUTHKIT_EMULATE_BASE_URL', 'http://localhost:4100'),
        'api_key' => env('AUTHKIT_EMULATE_API_KEY', 'sk_test_default'),
    ],

];
```

**Key decisions**:

- `env()` calls belong in this file and *only* this file. Verified empirically before writing this spec: `config/*.php` is outside every autoloaded PSR-4 namespace (`Authkit\Authkit\` → `src/`), and Pest's arch scanning derives its target set from Composer's PSR-4 map — a temporary `env()` call added to `config/authkit-laravel.php` and run through `vendor/bin/pest tests/ArchTest.php` passed clean. `tests/ArchTest.php` needs no change.
- `\App\Models\User::class` as a default is safe even though `App\Models\User` doesn't exist inside this package — `::class` is a compile-time string constant, PHP never attempts to autoload the class to resolve it. Standard practice for Laravel packages that need to default to the host app's conventional model.
- `routes.enabled`/`routes.prefix`/`routes.paths` are declared now but **not** wired into `AuthkitServiceProvider::boot()`'s `loadRoutesFrom()` call in this phase — `routes/authkit-laravel.php` has zero active routes today, so there is nothing observable to gate yet. Phase 2, which adds the real login/logout/callback routes, is responsible for making `loadRoutesFrom` conditional on `routes.enabled`. Wiring an if-check around an empty route file now would be untestable dead code.
- `PHPStan` (`phpstan.neon`) analyses `config/` at level 7 in this repo (not just `src/`) — this schema was written array-literal-only with no dynamic behavior specifically so it can't introduce analysis errors there.

No feedback loop: this is a config file (array literal, no branching logic to exercise) — trivial per the template's own guidance. Its correctness is exercised indirectly by every other component's tests (`WorkosClientManagerTest`, `ConfigCacheTest`, `InstallIdempotentTest`).

### 3. authkit:install Command (+ EnvFileUpdater)

**Pattern to follow**: `src/Console/Commands/AuthkitCommand.php` for command class shape; `src/AuthkitServiceProvider.php`'s existing `publishes()`/`publishesMigrations()` calls for the publish tags to target.

**Overview**: `authkit:install` publishes `config/authkit.php` and any package migrations, idempotently appends WorkOS env keys to `.env`/`.env.example`, and prints next steps. It never touches `config/auth.php` — the `workos` guard registers itself at runtime from inside the service provider (Phase 2's job), not by patching a host file.

```php
namespace Authkit\Authkit\Support;

final class EnvFileUpdater
{
    /**
     * @param array<string, string> $keys
     * @return array<int, string> keys actually appended
     */
    public function ensureKeys(string $path, array $keys): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $toAppend = [];
        foreach ($keys as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
                continue; // already present — this is the idempotency guard
            }
            $toAppend[$key] = $value;
        }

        if ($toAppend === []) {
            return [];
        }

        $lines = array_map(
            static fn (string $k, string $v): string => "{$k}={$v}",
            array_keys($toAppend),
            array_values($toAppend),
        );

        file_put_contents($path, rtrim($contents)."\n\n".implode("\n", $lines)."\n");

        return array_keys($toAppend);
    }
}
```

```php
namespace Authkit\Authkit\Console\Commands;

use Authkit\Authkit\Support\EnvFileUpdater;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'authkit:install';

    protected $description = 'Publish AuthKit config and migrations, and prepare .env keys.';

    public function handle(EnvFileUpdater $updater): int
    {
        $this->components->info('Publishing authkit-laravel resources...');
        $this->call('vendor:publish', ['--tag' => 'authkit-config']);
        $this->publishMigrationsIfNeeded();

        $cookiePassword = base64_encode(random_bytes(32));

        $appended = $updater->ensureKeys(base_path('.env'), $this->envKeys($cookiePassword));
        $updater->ensureKeys(base_path('.env.example'), $this->envKeys('')); // never write a real secret into .env.example

        if ($appended === []) {
            $this->warn('No .env file was updated. Set these keys manually if needed:');
            foreach (array_keys($this->envKeys('')) as $key) {
                $this->line("  {$key}");
            }
        }

        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function publishMigrationsIfNeeded(): void
    {
        $sourcePath = __DIR__.'/../../../database/migrations';

        if ($this->migrationsAlreadyPublished($sourcePath)) {
            $this->components->twoColumnDetail('AuthKit migrations', '<fg=yellow;options=bold>ALREADY PUBLISHED</>');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'authkit-laravel-migrations']);
    }

    /**
     * `database.migrations.update_date_on_publish` defaults to true (Laravel 11+), so
     * vendor:publish always re-timestamps and re-copies migrations on every run unless
     * we check first. Match by stripping each source migration's leading Y_m_d_His_
     * timestamp and globbing the destination for a file already ending in that name.
     */
    private function migrationsAlreadyPublished(string $sourcePath): bool
    {
        foreach (glob($sourcePath.'/*.php') ?: [] as $sourceFile) {
            $descriptor = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($sourceFile));

            if (glob(database_path("migrations/*_{$descriptor}")) === []) {
                return false; // this source migration has never been published
            }
        }

        return true; // every source migration already has a published counterpart (or there are none)
    }

    /** @return array<string, string> */
    private function envKeys(string $cookiePassword): array
    {
        return [
            'WORKOS_API_KEY' => '',
            'WORKOS_CLIENT_ID' => '',
            'WORKOS_REDIRECT_URI' => '${APP_URL}/authkit/callback',
            'WORKOS_COOKIE_PASSWORD' => $cookiePassword,
            'WORKOS_BASE_URL' => 'https://api.workos.com',
        ];
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps:');
        $this->line('  1. Set WORKOS_API_KEY and WORKOS_CLIENT_ID in .env from your WorkOS dashboard.');
        $this->line('  2. Confirm WORKOS_REDIRECT_URI matches the redirect URI configured in AuthKit.');
        $this->line('  3. For local dev against workos/emulate, set AUTHKIT_EMULATE_ENABLED=true.');
        $this->line('  4. Run php artisan authkit:inspect-token against a real AuthKit login and follow');
        $this->line('     docs/token-audit.md before Phase 2 implementation begins.');
    }
}
```

**Key decisions**:

- `EnvFileUpdater` is extracted as a plain, container-free class rather than inlined into the command. Reason is parallel-test-safety, not abstraction for its own sake: `composer test:unit` runs `vendor/bin/pest --parallel`, and Testbench's skeleton app directory may be shared across parallel workers. A pure class with an explicit `$path` parameter lets unit tests point at throwaway `tempnam()` files instead of racing on `base_path('.env')`.
- `.env` gets a freshly generated `WORKOS_COOKIE_PASSWORD`; `.env.example` always gets an empty string for it. Reusing the same generated value for both would risk a real, usable secret landing in a file that's conventionally committed to git.
- Migration idempotency requires explicit dedup logic — it is **not** free. `database.migrations.update_date_on_publish` defaults to `true` as of Laravel 11 (confirmed directly in `vendor/laravel/framework/config/database.php` and `vendor/orchestra/testbench-core/laravel/config/database.php` — the exact skeleton this repo's own Pest suite boots against — and in the live `laravel/laravel` skeleton for Laravel 12/13). With that default, `ServiceProvider::ensureMigrationNameIsUpToDate()` re-timestamps the destination filename on every `vendor:publish` call, and the existence check in `VendorPublishCommand::publishFile()`/`moveManagedFiles()` runs against the *original*, un-retimestamped path — a path that's never actually written to disk — so it is always false and a fresh duplicate migration file is copied on every re-run. `InstallCommand::publishMigrationsIfNeeded()` therefore does its own existence check first: it strips the leading `Y_m_d_His_` timestamp off each source migration's filename and globs `database_path('migrations')` for a file already ending in that same descriptor, calling `vendor:publish --tag=authkit-laravel-migrations` only when at least one source migration has no published counterpart yet.
- Routes need no idempotency handling because `authkit:install` never touches them — they load live via `loadRoutesFrom()` in the service provider, not via a copy step.
- Does **not** touch `config/auth.php`. The `workos` guard (Phase 2) registers via `Auth::extend()` inside the service provider at boot time, not by writing guard config into the host app's file.

**Implementation steps**:

1. Write `src/Support/EnvFileUpdater.php` first (no dependencies, fully unit-testable in isolation).
2. Write `src/Console/Commands/InstallCommand.php`, using method injection for `EnvFileUpdater` (standard Laravel container resolution on `handle()`).
3. Register `InstallCommand::class` in `AuthkitServiceProvider::boot()`'s existing `$this->commands([...])` array, alongside `AuthkitCommand::class`.
4. Update `AuthkitServiceProvider`'s config `publishes()` call to tag `['authkit-laravel', 'authkit-config']` instead of `['authkit-laravel', 'authkit-laravel-config']`.

**Feedback loop**:

- **Playground**: `tests/Unit/EnvFileUpdaterTest.php` (pure, tmp-file-backed) for the core logic; `tests/Feature/InstallIdempotentTest.php` (one `$this->artisan('authkit:install')` integration test) for wiring.
- **Experiment**: run `ensureKeys()` against a scratch file with 0 existing keys, then again against the *same file* after the first run — assert the second call returns `[]` (nothing appended) and the file's byte content is unchanged from after the first call. Separately: run against a scratch file that already has `WORKOS_API_KEY=` set to a custom value and assert that line is untouched.
- **Check command**: `vendor/bin/pest --filter=InstallIdempotent` (and `vendor/bin/pest --filter=EnvFileUpdater` for the unit-level cases)

### 4. Test Harness Plumbing (EmulateServer + MockHandler trait)

**Pattern to follow**: `tests/TestCase.php`, `tests/Pest.php` for how the existing suite wires Testbench; `vendor/laravel/framework/src/Illuminate/Process/PendingProcess.php` for the exact process-builder API (`path()`, `env()`, `start()` returning `InvokedProcess`; `InvokedProcess::stop(float $timeout = 10, ?int $signal = null)`) — read directly, available only because `orchestra/testbench` pulls the full `laravel/framework` into `require-dev`.

**Overview**: Two independent pieces of test infrastructure every later phase's Pest suites will reuse: `EmulateServer` (boots a real `workos/emulate` process for wire-fidelity tests) and `UsesWorkosMockHandler` (a trait for tests that need to fake specific HTTP responses/failures, per the truth-bar decision).

```php
namespace Authkit\Authkit\Tests\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

final class EmulateServer
{
    private ?InvokedProcess $process = null;

    public function __construct(
        private readonly int $port = 4100,
        private readonly string $seedPath = __DIR__.'/../Fixtures/workos-emulate.config.yaml',
    ) {
    }

    public static function isAvailable(): bool
    {
        return Process::run('npx --version')->successful();
    }

    public function baseUrl(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    public function start(): void
    {
        $this->process = Process::env(['PORT' => (string) $this->port])
            ->start("npx --yes @workos/emulate@^0.6 --seed={$this->seedPath}");

        $this->waitForHealth();
    }

    public function stop(): void
    {
        $this->process?->stop();
        $this->process = null;
    }

    private function waitForHealth(int $timeoutSeconds = 15): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (@file_get_contents($this->baseUrl().'/health') !== false) {
                return;
            }
            usleep(200_000);
        }

        throw new \RuntimeException(
            "workos/emulate did not report healthy at {$this->baseUrl()}/health within {$timeoutSeconds}s.",
        );
    }
}
```

```php
namespace Authkit\Authkit\Tests\Concerns;

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

trait UsesWorkosMockHandler
{
    protected MockHandler $workosMockHandler;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $workosRequestHistory = [];

    /** @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $responses */
    protected function fakeWorkosResponses(array $responses): MockHandler
    {
        $this->workosMockHandler = new MockHandler($responses);
        $this->workosRequestHistory = [];

        $stack = HandlerStack::create($this->workosMockHandler);
        $stack->push(Middleware::history($this->workosRequestHistory));

        $this->app->instance(HandlerStack::class, $stack);
        $this->app->forgetInstance(WorkosClientManagerContract::class);

        return $this->workosMockHandler;
    }
}
```

**Key decisions**:

- `EmulateServer::isAvailable()` exists so every emulate-backed test can `->skip()` gracefully on a machine without Node, rather than hanging or hard-failing `composer test`. This phase's own `EmulateServerTest` uses it; every later phase's emulate-backed suite must too.
- `fakeWorkosResponses()` calls `forgetInstance(WorkosClientManagerContract::class)` — without it, a test running after another test has already resolved the singleton would silently keep using the *old* `HandlerStack`, since `WorkosClientManager` caches its built `WorkOS` client internally.
- `Middleware::history()` is pushed onto the stack alongside the `MockHandler` so tests can make assertions against the actual outgoing request (headers, body, URL) — not just the canned response.
- `EmulateServer`'s seed fixture lives under `tests/Fixtures/` and is not published to consumer apps in this phase. The contract's "emulate DX" scope row (publishable seed, CI recipe, `php artisan dev` wiring) is explicitly Phase 13's job ("emulate CI recipe"); Phase 1 only needs the harness to work for *this repo's own* test suite.

**Implementation steps**:

1. Write `tests/Fixtures/workos-emulate.config.yaml` with a minimal seed (one organization, one user) — enough for a boot/health smoke test; later phases extend it as they add emulate-backed suites.
2. Write `tests/Support/EmulateServer.php`.
3. Write `tests/Concerns/UsesWorkosMockHandler.php`.
4. Write `tests/Feature/EmulateServerTest.php` guarded by `EmulateServer::isAvailable()`.

**Feedback loop**:

- **Playground**: `tests/Feature/EmulateServerTest.php` for the process-management side; any test using `UsesWorkosMockHandler` (e.g. `WorkosClientManagerTest`) for the mock-handler side.
- **Experiment**: start `EmulateServer`, hit `baseUrl().'/health'` directly, assert 2xx-ish reachability, then `stop()` and assert the port is free again (a second `start()` on the same port succeeds). Separately: call `fakeWorkosResponses()` twice in sequence within one test and confirm the *second* call's responses are what get consumed (proving the rebind actually takes effect, not a stale first stack).
- **Check command**: `vendor/bin/pest --filter=EmulateServer` (slow path, run deliberately — not part of the fast inner loop) and `vendor/bin/pest --filter=WorkosClientManager` (exercises the mock-handler trait)

### 5. authkit:inspect-token Command

**Pattern to follow**: `vendor/workos/workos-php/lib/SessionManager.php`'s `unsealData()` (public static — safe to call directly) and `decodeAccessToken()` (private — read its algorithm, do not attempt to call it via reflection; reimplement the ~10-line base64url+JSON decode, which is standard, stable JWT-spec behavior, not something that needs the SDK's internals).

**Overview**: A dev-only diagnostic that accepts either a raw JWT access token or a sealed AuthKit session string, decodes it (no signature verification — this is an inspection tool, not the auth path), and prints every claim relevant to the token audit, explicitly flagging absent claims.

```php
namespace Authkit\Authkit\Console\Commands;

use Illuminate\Console\Command;
use WorkOS\SessionManager;

class InspectTokenCommand extends Command
{
    protected $signature = 'authkit:inspect-token
        {token? : The AuthKit token or sealed session string to inspect}
        {--cookie-password= : Override config(authkit.cookie_password) when unsealing}';

    protected $description = 'Decode a pasted AuthKit token (dev-only) to inspect iss/aud/claims for the token audit.';

    private const CLAIM_KEYS = [
        'iss', 'aud', 'sub', 'client_id', 'org_id', 'role', 'roles',
        'permissions', 'entitlements', 'feature_flags', 'sid', 'jti', 'exp', 'iat',
    ];

    public function handle(): int
    {
        $this->warn('Dev-only tool: decodes raw token claims. Do not paste production user tokens into shared terminals/CI logs.');

        $input = $this->argument('token');
        if ($input !== null) {
            $this->warn('Passing the token as an argument may leak it into shell history; prefer the interactive prompt.');
        } else {
            $input = $this->secret('Paste the AuthKit token or sealed session string');
        }

        if (! is_string($input) || trim($input) === '') {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        try {
            $claims = $this->decodeJwtPayload($this->resolveAccessToken($input));
        } catch (\Throwable $e) {
            $this->error("Could not decode token: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->printClaims($claims);

        $this->newLine();
        $this->line('Record the iss/aud values above into config/authkit.php (jwt.issuer / jwt.audience)');
        $this->line('and into docs/token-audit-findings.md before Phase 2 implementation begins.');

        return self::SUCCESS;
    }

    private function resolveAccessToken(string $input): string
    {
        if (substr_count($input, '.') === 2) {
            return $input; // already looks like a raw header.payload.signature JWT
        }

        $cookiePassword = $this->option('cookie-password') ?? config('authkit.cookie_password');
        if (! is_string($cookiePassword) || $cookiePassword === '') {
            throw new \RuntimeException('No cookie password configured; pass --cookie-password or set authkit.cookie_password.');
        }

        $session = SessionManager::unsealData($input, $cookiePassword);
        if (! isset($session['access_token']) || ! is_string($session['access_token'])) {
            throw new \RuntimeException('Unsealed session has no access_token.');
        }

        return $session['access_token'];
    }

    /** @return array<string, mixed> */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Not a valid JWT (expected header.payload.signature).');
        }

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($claims)) {
            throw new \RuntimeException('JWT payload is not valid JSON.');
        }

        return $claims;
    }

    private function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Malformed base64url segment.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $claims */
    private function printClaims(array $claims): void
    {
        $this->newLine();
        foreach (self::CLAIM_KEYS as $key) {
            $value = $claims[$key] ?? null;
            $display = match (true) {
                $value === null => '(not present)',
                is_array($value) => $value === [] ? '(empty array)' : implode(', ', $value),
                in_array($key, ['exp', 'iat'], true) => sprintf('%s (%s)', $value, date(DATE_ATOM, (int) $value)),
                default => (string) $value,
            };
            $this->components->twoColumnDetail($key, $display);
        }
    }
}
```

**Key decisions**:

- Decoding is unverified by design (no JWKS fetch, no signature check) — the audit needs to *see* claims even from a token whose signature can't be checked offline (e.g. one that's since expired). Verification belongs to Phase 2's guard, not this inspector.
- `decodeAccessToken()` on `SessionManager` is `private` — reflection into it would be fragile and tie this command to SDK internals that can change. Reimplementing the ~10-line base64url-JSON decode (standard, stable JWT-spec algorithm) is the more robust choice.
- No hard environment gate (e.g. refusing to run outside `local`). A hard gate would be speculative — Laravel doesn't gate its own introspection commands by environment, and the real risk (accidentally printing a production user's claims into a shared terminal/CI log) is addressed with a warning banner instead, which is proportionate.
- `(not present)` is a fixed, greppable sentinel string, used consistently so tests can assert on it directly.

**Implementation steps**:

1. Write `src/Console/Commands/InspectTokenCommand.php`.
2. Register it in `AuthkitServiceProvider::boot()`'s `commands()` array.
3. Write `docs/token-audit.md` and `docs/token-audit-findings.md` (component 6) — the command's final printed lines reference both files by name, so they should exist before this command ships.

**Feedback loop**:

- **Playground**: `tests/Feature/InspectTokenCommandTest.php`, using `$this->artisan('authkit:inspect-token', ['token' => $fixture])`.
- **Experiment**: (a) build a synthetic JWT by hand (`base64url(json_encode($header)) . '.' . base64url(json_encode($payload)) . '.fakesig'`) with a payload containing `iss`, `aud`, `role`, `permissions`, `feature_flags` and assert each prints its real value; (b) the same payload with `feature_flags` omitted and assert it prints `(not present)`; (c) wrap that same JWT in `SessionManager::sealData(['access_token' => $jwt], $password)` (public static — safe to call from a test) and feed the sealed string in with `--cookie-password=$password`, asserting identical output to the raw-JWT path; (d) feed garbage input and assert `FAILURE` with a clean error message, no stack trace.
- **Check command**: `vendor/bin/pest --filter=InspectTokenCommand`

### 6. Token Audit Procedure & Findings Record

**Overview**: Phase 1's actual deliverable per the contract's decision log — not code, a documented, human-executed procedure plus the durable record Phase 2 depends on. `docs/token-audit.md` is the how-to; `docs/token-audit-findings.md` is the recorded result.

`docs/token-audit.md` must instruct the operator to:

1. Use a real WorkOS dashboard test/sandbox environment (not `workos/emulate` — emulate doesn't produce tokens signed by the real production issuer, so it cannot answer the "what is the canonical `iss`" question).
2. Set real `WORKOS_API_KEY`/`WORKOS_CLIENT_ID` and complete one real AuthKit login to obtain a real access token. `docs/token-audit.md` must include the following throwaway script verbatim. It builds the authorization URL via `WorkOS\Service\UserManagement::getAuthorizationUrl()`, which returns a plain `string` built locally by `HttpClient::buildUrl()` — pure string construction, no HTTP request (confirmed in `vendor/workos/workos-php/lib/Service/UserManagement.php:484-522` and `HttpClient.php:90-99`) — with the PKCE pair supplied by `PKCEHelper::generate()`, a pure static helper (confirmed in `PKCEHelper.php:60-70`). It deliberately does **not** use `PKCEHelper::getAuthKitAuthorizationUrl()`: that method issues a real HTTP GET to `user_management/authorize` via `$this->client->request()` and assigns the *decoded response body* to `$auth['url']` (confirmed in `PKCEHelper.php:118-129`) — not a URL string. Guzzle follows the endpoint's redirect by default, so the GET actually lands on the real AuthKit-hosted HTML login page; `HttpClient::decodeResponse()` then `json_decode`s that HTML body, gets a non-array, and throws `ApiException` (confirmed in `HttpClient.php:312-337`) — the sketch would throw before it ever reaches `header('Location: ...')`. `WorkOS::pkce()->authKitCodeExchange()` (confirmed in `WorkOS.php:233-236`) is a real POST by design and is unaffected — it's used unchanged for the token exchange below:

   ```php
   <?php
   // audit-callback.php — throwaway script, delete after use.
   require __DIR__.'/vendor/autoload.php';

   use WorkOS\PKCEHelper;
   use WorkOS\Resource\UserManagementAuthenticationProvider;
   use WorkOS\WorkOS;

   $client = new WorkOS(apiKey: getenv('WORKOS_API_KEY'), clientId: getenv('WORKOS_CLIENT_ID'));
   $redirectUri = 'http://localhost:8080/audit-callback.php';

   if (! isset($_GET['code'])) {
       $pkce = PKCEHelper::generate();
       file_put_contents(__DIR__.'/.audit-verifier', $pkce['code_verifier']);

       $url = $client->userManagement()->getAuthorizationUrl(
           redirectUri: $redirectUri,
           codeChallengeMethod: $pkce['code_challenge_method'],
           codeChallenge: $pkce['code_challenge'],
           provider: UserManagementAuthenticationProvider::Authkit,
       );
       header('Location: '.$url);
       exit;
   }

   $verifier = file_get_contents(__DIR__.'/.audit-verifier');
   $result = $client->pkce()->authKitCodeExchange($_GET['code'], $verifier);

   echo $result['access_token']; // paste this into `php artisan authkit:inspect-token`
   ```

   Save it as `audit-callback.php` at the project root, add `http://localhost:8080/audit-callback.php` as a redirect URI in the WorkOS dashboard, run `php -S localhost:8080`, then visit that URL in a browser and complete the AuthKit login — the script prints the access token to paste into step 3.
3. Run `php artisan authkit:inspect-token` and paste it in.
4. Record the printed `iss`, `aud`, and the presence/absence of `role`, `roles`, `permissions`, `feature_flags`.
5. Update `config/authkit.php`'s `jwt.issuer`/`jwt.audience` defaults with the confirmed literal values (replacing the `null` placeholders).
6. Append the findings to `docs/token-audit-findings.md` (who ran it, when, against which WorkOS environment).
7. State explicitly: Phase 2's guard-level `iss`/`aud` enforcement must not begin until steps 5–6 are done with real, non-placeholder values.

`docs/token-audit-findings.md` ships with this phase in a `TBD` state — a table with columns for the field, value, who confirmed it, when, and against which WorkOS environment, all rows reading `TBD`. This file's placeholder state is expected and correct at the end of Phase 1; it is Phase 2's spec's job to check it before starting guard work.

No feedback loop: this is documentation plus a manual procedure, not executable logic — trivial per the template's own guidance. Its "test" is the human checklist item under Manual Testing below.

## Data Model

Not applicable. Phase 1 introduces no persisted state. The one existing migration (a dead placeholder) is deleted; real projection migrations (user `workos_id` column, organization tables) begin in Phase 2/3.

## API Design

Not applicable. Phase 1 ships no HTTP endpoints — only container bindings and console commands, documented under Implementation Details.

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/EnvFileUpdaterTest.php` | Idempotent key-appending against scratch tmp files: missing keys appended once, present keys left untouched, second run is a byte-identical no-op, missing file returns `[]` without erroring |

**Key test cases**:

- Appending to a file with none of the target keys present appends all of them.
- Running the same call twice against the same file: second call returns `[]`, file content unchanged between calls.
- A file where one target key already has a custom value: that line is untouched, other missing keys still get appended.
- Path pointing at a nonexistent file: returns `[]`, does not attempt to create it.
- Edge case: a key name that is a prefix of another existing key (e.g. `WORKOS_API_KEY` vs `WORKOS_API_KEY_2`) does not false-positive-match via the `^KEY=` regex.

### Feature Tests

| Test File | Coverage |
| --- | --- |
| `tests/Feature/WorkosClientManagerTest.php` | Config resolution → `WorkOS` client construction; emulate override; never-triggers-SDK-env-fallback |
| `tests/Feature/ConfigCacheTest.php` | `config:cache` compatibility; no `env(` in `src/` |
| `tests/Feature/InstallIdempotentTest.php` | `authkit:install` end-to-end wiring + idempotency |
| `tests/Feature/InspectTokenCommandTest.php` | Raw-JWT and sealed-session decode paths, absent-claim reporting, error handling |
| `tests/Feature/EmulateServerTest.php` | Process boot/health/stop, self-skipping when unavailable |

**Key test cases**:

- `WorkosClientManagerTest`: resolving the singleton twice returns the same object (cached); resolving `client()` twice returns the same `WorkOS` instance; with `authkit.emulate.enabled=true`, the resolved base URL and api key come from the `emulate.*` keys, not the top-level ones; with a poisoned `WORKOS_API_KEY` env var and a distinct configured `authkit.api_key`, a captured outgoing request's `Authorization` header matches the *configured* value.
- `ConfigCacheTest`: `$this->artisan('config:cache')->assertSuccessful()`, then `config('authkit.base_url')` still resolves to its default, and `app(WorkosClientManagerContract::class)->client()` does not throw; **must** call `$this->artisan('config:clear')` in `afterEach()` unconditionally (Pest runs `afterEach` even on failure) to avoid leaking a cached config file into later tests in the same parallel worker. A separate, non-Pest assertion: recursively scan `src/` for the literal substring `env(` and assert zero matches (belt-and-suspenders alongside the CI-level grep).
- `InstallIdempotentTest`: seed a scratch `.env`/`.env.example` under the Testbench skeleton's `base_path()` in `beforeEach`, delete them in `afterEach` (this is the *only* test file touching those paths, by design, to stay parallel-safe); run `authkit:install` once, assert every `WORKOS_*` key present exactly once and `.env.example`'s `WORKOS_COOKIE_PASSWORD` is empty while `.env`'s is a non-empty generated value; run it a second time, assert `.env` content is byte-identical to after the first run.
- `InspectTokenCommandTest`: see component 5's Experiment list above — covers both input shapes, an omitted-claim case, and a malformed-input failure case.
- `EmulateServerTest`: `->skip(fn () => ! EmulateServer::isAvailable(), 'npx/node not available')` at the top; when it does run, `start()` then a direct `file_get_contents($server->baseUrl().'/health')` succeeds, then `stop()`, then confirm a fresh `start()` on the same port succeeds again (port was actually released).

### Manual Testing

- [ ] Run `docs/token-audit.md`'s procedure end-to-end against a real WorkOS sandbox account, and populate `docs/token-audit-findings.md` with real values (blocks Phase 2 guard work until done).
- [ ] Run `php artisan authkit:install` against a throwaway real Laravel app (not just Testbench) and confirm the printed next steps make sense to a first-time reader.
- [ ] Confirm `php artisan authkit:inspect-token`'s interactive `secret()` prompt does not echo the pasted value to the terminal.
- [ ] On a machine with Node installed, confirm `EmulateServer` leaves port 4100 free after a test run that fails mid-way (kill a test with `Ctrl+C` mid-`start()` and check `lsof -i :4100` afterward).

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| WorkosClientManager | SDK env fallback silently activates | A future call site passes raw `null` instead of `''` for `apiKey`/`clientId` | `WorkOS::__construct()`'s `??= getenv(...)` kicks in; requests silently authenticate against a different WorkOS environment than intended | `fromConfig()` always casts through `(string)` with an explicit `''` default; regression test asserts the actual `Authorization` header via a poisoned env var (see Testing Requirements) |
| WorkosClientManager | Emulate flag left on outside local/testing (stale config shadow) | `AUTHKIT_EMULATE_ENABLED=true` accidentally ships to a staging/production `.env` | All WorkOS calls silently redirect to `localhost:4100`; looks identical to "WorkOS is down" | Named in `docs/token-audit.md`/install next-steps as dev/test-only; no runtime guard added (would need environment-name sniffing this package deliberately avoids) — named risk, not code-fixed |
| WorkosClientManager | WorkOS API unreachable (WorkOS-down path) | Network partition, WorkOS incident, or `base_url` pointed at a not-yet-started emulate process | `HttpClient` throws `ConnectionException`/`TimeoutException` after exhausting `maxRetries` (429/5xx retried with jitter, per vendored `HttpClient.php`) | Retry/backoff is inherited for free from the SDK's `HttpClient` — later phases must not build a second retry layer on top |
| WorkosClientManager / MockHandler trait | Stale `HandlerStack` served after rebind (race) | `fakeWorkosResponses()` called after `WorkosClientManagerContract` was already resolved+cached earlier in the same test/worker | New mock responses never get used; test hits a leftover fake, flaky/spooky failure | `fakeWorkosResponses()` calls `forgetInstance(WorkosClientManagerContract::class)`, forcing a fresh build against the newly-bound stack |
| config:cache | Cached config file leaks between parallel test workers (race) | `ConfigCacheTest` runs `config:cache` without a guaranteed `config:clear` afterward | Later tests in the same worker read stale cached values instead of test-set overrides, causing unrelated confusing failures | `afterEach()` unconditionally runs `config:clear`; this is the only test file allowed to touch the cache |
| authkit:install / EnvFileUpdater | Duplicate env keys from a naive re-run | Running `authkit:install` twice without the presence check | `.env` gets two `WORKOS_API_KEY=` lines; dotenv silently uses the *last* one, so editing the first is silently ignored later | `ensureKeys()` regex-checks for `^KEY=` before appending anything |
| authkit:install / EnvFileUpdater | Generated secret committed via `.env.example` (data shadow) | Reusing the same generated cookie password for both `.env` and `.env.example` | A real, usable session-encryption key lands in a file conventionally committed to git | `.env.example` always receives an empty-string placeholder; only real `.env` gets the generated value |
| authkit:install | Migration republish duplicates files | Running `authkit:install` a second time — `database.migrations.update_date_on_publish` defaults to `true` since Laravel 11 (confirmed in the vendored framework/testbench configs and the live `laravel/laravel` skeleton), so this is the out-of-the-box behavior for every Laravel 12/13 app, not an opt-in edge case | Without a guard, each install re-run re-timestamps and re-copies the migration stub, leaving duplicate files Laravel then tries to run twice | `InstallCommand::migrationsAlreadyPublished()` globs `database_path('migrations')` for a file already matching each source migration's non-timestamp descriptor before calling `vendor:publish --tag=authkit-laravel-migrations`, skipping the call once every source migration is already published — a coded guard, not a documentation note |
| authkit:install | No writable `.env` (containerized/env-var-only deploys) | `.env`/`.env.example` don't exist on disk | Naive file writes would throw or silently no-op in a confusing way | `ensureKeys()` early-returns `[]` when the target isn't a file; command detects the empty result and prints the key list to set manually |
| EmulateServer | npx/Node unavailable (WorkOS-down / environment path) | Dev machine or CI image lacks Node.js | Naive process start could hang the whole suite waiting on `/health` indefinitely | `isAvailable()` pre-flight check gates every emulate-backed test via `->skip()`; never lets `composer test` hang or hard-fail for this reason |
| EmulateServer | Health check never succeeds | Port collision, corrupt seed YAML, slow CI runner | Unbounded polling would hang the suite | Explicit 15s timeout throws a clear `RuntimeException` naming the URL and elapsed time |
| EmulateServer | Orphaned background process after a failed test | Assertion failure/exception between `start()` and `stop()` | The `npx`/node child process keeps running, holds port 4100, breaks the *next* test run with "port in use" | `stop()` must be registered in `afterEach()`, not only at the happy-path end of a test body |
| EmulateServer | In-memory emulate state leaks between later phases' tests (data shadow) | Emulate resets its store only on process restart or its own `/_emulate/hooks` reset (which is documented to break auth-event webhooks) | A user/org created by one test could leak into a later test's assertions if the same process is reused across files | Named as an Open Item for whichever phase first writes an emulate-backed functional test (Phase 2) — Phase 1's own test is boot/health only, no state mutation |
| authkit:inspect-token | Malformed/truncated paste | Developer copies a partial token or the wrong value (e.g. refresh token) | Decode throws | Caught and rewrapped into a clean `"Could not decode token: ..."` message, `FAILURE`, no stack trace |
| authkit:inspect-token | Wrong `cookie_password` | Unsealing with an incorrect key | SDK's `sodium_crypto_secretbox_open` fails, `SessionManager::unsealData()` throws `InvalidArgumentException('Decryption failed...')` (verified in source) | Caught and rewrapped into a "check `--cookie-password`/`authkit.cookie_password`" message |
| authkit:inspect-token | Token leaks into shell history | Developer passes the token as a CLI argument instead of using the interactive prompt | Token persists in `.bash_history`/`.zsh_history` indefinitely | Warning printed whenever the `token` argument is non-empty; `docs/token-audit.md` documents the interactive prompt as the default |
| Token audit procedure | Findings never actually get recorded | Procedure run mentally/verbally but `docs/token-audit-findings.md` never updated | Phase 2 has nothing authoritative to enforce `iss`/`aud` against; the audit's purpose is defeated | Named explicitly as a hard human-process gate in Open Items and Manual Testing — not a code bug, a process discipline requirement |

## Validation Commands

```bash
# Install dependencies (after composer.json changes)
composer install

# Full validation — must be green before calling this phase done
composer test

# Fast inner loop while iterating on Phase 1 components
vendor/bin/pest --filter="WorkosClientManager|EnvFileUpdater|ConfigCache|InstallIdempotent|InspectTokenCommand"

# Slow path — only when working on the process-management code itself
vendor/bin/pest --filter=EmulateServer

# Static analysis only
composer analyse

# Formatting check only
composer lint:check

# 100% type coverage of src/ only
composer test:types

# Config-only credentials doctrine — must exit 1 (no matches)
grep -rn "env(" src/ --include="*.php"

# Confirm the arch suite's env() ban doesn't (and shouldn't) touch config/
vendor/bin/pest tests/ArchTest.php
```

## Rollout Considerations

- **Feature flag**: none — this is infrastructure with no user-facing behavior to gate.
- **Monitoring**: none new; nothing in this phase runs in production request paths.
- **Alerting**: none new.
- **Rollback plan**: this phase executes directly on `main` per the contract's express-run decision (no isolation branch). Rollback is a normal `git revert` of this phase's commit(s); the documented recovery anchor for the whole express run is `git reset --hard e845a2f`.

## Open Items

- [ ] Namespace rename (`Authkit\Authkit` → TBD) is explicitly deferred — not part of this phase, called out here so no implementer accidentally starts it while touching `AuthkitServiceProvider.php`.
- [ ] `docs/token-audit.md`'s procedure has not been executed as part of writing this spec. `docs/token-audit-findings.md` ships with placeholder `TBD` values. A human with real WorkOS dashboard access must run the procedure and update both that file and `config/authkit.php`'s `jwt.issuer`/`jwt.audience` defaults before Phase 2 begins guard-level `iss`/`aud` enforcement.
- [ ] Whether Testbench's skeleton directory (`vendor/orchestra/testbench-core/laravel`) is shared or per-process across `--parallel` workers was not conclusively verified. This spec's design assumes it *may* be shared and isolates every side-effecting test accordingly (single-owner file + explicit cleanup) rather than relying on process isolation — revisit if `composer test:unit --parallel` proves flaky around `InstallIdempotentTest`/`ConfigCacheTest`.
- [ ] `workbench/.env.example` does not yet carry `WORKOS_*`/emulate keys. Deferred to the phase that first exercises a real login against the workbench app (Phase 2).
- [ ] The exact `php artisan dev` extension mechanism for wiring the events worker + emulate boot together (named in the contract) is not implemented in this phase — `EmulateServer` here is test-harness-only. Verify the mechanism against current Laravel docs when Phase 4/13 wires it.
- [ ] The publishable, consumer-facing emulate seed file + CI recipe (the broader "emulate DX" scope row) is intentionally deferred to Phase 13 ("emulate CI recipe" per its phase notes) — `tests/Fixtures/workos-emulate.config.yaml` here is test-only, not published.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
