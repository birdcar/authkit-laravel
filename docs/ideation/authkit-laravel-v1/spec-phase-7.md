# Phase 7 — Feature Flags (Pennant Driver)

**Follow `spec-template-feature-area.md`; inputs below.** This delta fills every item in that template's "Delta Must Fill" list. Do not re-read this as a standalone doc without the template — conventions, shared feedback strategy, standard validation commands, and shared failure-mode prompts live there and are not repeated here except where this phase deviates.

## 1. Phase Header

- **Phase**: 7 of 13 — Feature Flags (Pennant Driver)
- **Risk (contract)**: low — small blast radius, no new infra, no migrations, no HTTP surface of its own.
- **Effort (this delta)**: **M**. Risk and effort are different axes: this phase implements the full 8-method Pennant `Driver` contract, two distinct resolution paths (zero-HTTP claims + cache-fronted API fallback with stale-serve-on-down), a cross-package config-injection seam into `laravel/pennant`'s own config namespace, and a global default-scope override — more design and test surface than a trivial config/DTO phase, but every piece is a direct, unambiguous mapping to an existing SDK method or Pennant contract method, with no blocking unknowns.
- **Prereqs**: Auth Core & Sealed Sessions (Phase 2) — needs the `workos` guard to exist as an authentication source for the zero-HTTP claims path. Foundation & Client Binding (Phase 1) transitively, for `WorkosClientManager`.
- **Dependency decision (binding)**: add `laravel/pennant` to **`require`** (not `require-dev`) in `composer.json`, pinned `"laravel/pennant": "^1.22"`. `^1.22` is the first Pennant tag whose `illuminate/support` constraint includes `^13.0` (verified against `laravel/pennant` tags `v1.20.0`→`v1.24.0` on GitHub: `v1.21.0` still caps at `^12.0`, `v1.22.0` adds `^13.0`). Pinning below `^1.22` would let `composer update --prefer-lowest` select a Pennant version that cannot run on Laravel 13, silently breaking the L13 lane of the CI matrix. This is a real dependency, not a dev/test-only one: every app installing `authkit-laravel` gets a working `workos` Pennant store, matching "Pennant checks must work everywhere Laravel runs them."

## 2. Scope Rows Implemented (verbatim from contract)

- MVP: *"Feature Flags: first-party laravel/pennant driver — JWT feature_flags claim inside authenticated requests, WorkOS API fallback for queued jobs / console (Pennant checks must work everywhere Laravel runs them)"* — reason: *"Pennant is Laravel's paved path for flags; both contexts carry success criteria."*
- Execution notes (contract `execution.phases[6]`): *"Pennant driver: feature_flags claim inside authenticated requests, WorkOS API fallback for queued jobs / console, cache + refresh semantics."*
- Success criterion covered: *"Feature flags resolve in both contexts: from JWT claims inside an authenticated HTTP request, and via the WorkOS API fallback in a queued job / console context with no session"* — check: `vendor/bin/pest --filter=FeatureFlags` exits 0.
- No Full-tier items for this area (Depth Extensions phase's list — invitations, JWT templates/CORS, groups, FGA resource-graph, FGA cache — does not include Feature Flags).

## 3. Decisions Considered and Rejected

### Carried from the contract decision log

| Decision (contract) | Rejected alternative | Reason | Relevance here |
|---|---|---|---|
| Feature Flags ship as a first-party `laravel/pennant` driver (claim-first, API fallback) | Standalone `AuthkitFeature` facade | Pennant is Laravel's paved path — `Feature::active()`, `@feature`, and middleware come free, matching the Laravel-native doctrine | This is the phase's charter — directly implemented below |
| RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API | Sync WorkOS roles/permissions into local spatie-style tables | Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift | Precedent this driver reuses: claims-first, API as the explicit (not default) escalation path |
| Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id ↔ external_id` linking, refreshed by the events pipeline | No local state / read-through API calls per request | Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events | Feature flags are **not** a declared projection — this driver must not add a `feature_flags` table; the only local state is a short-TTL cache entry, not a projection (see §9 Deviations = None, but see Decision D-6 below) |
| Credentials read from config only; `env()` never read outside config files | Runtime `env()` reads like the SDK's own fallback does | `php artisan config:cache` empties env at runtime (laravel/framework#55028 class of bug) | Driver reads `config('authkit.*')` only; the config-cache interaction with `laravel/pennant`'s own config is the subject of Decision D-4 below |
| Stay on Pest 4 with PHP ^8.3 floor | Pest 5 | PHP 8.3 supported until Dec 2027; Laravel 13 supports it | Test suite below is Pest 4, MockHandler-backed |

### Phase-specific decisions (new in this delta)

| ID | Decision | Rejected alternative | Reason |
|---|---|---|---|
| D-1 | `WorkosPennantDriver::define()` **throws** `RuntimeException` naming the feature and pointing at the WorkOS Dashboard | (a) silent no-op; (b) store the closure and actually use it as a fallback resolver | Flags are Dashboard-defined, not Laravel-authored. A silent no-op would make `Feature::define('x', fn () => true)` look like it worked while doing nothing — a worse footgun than a loud, actionable exception. Storing and using the closure would make local code the source of truth for a subset of flags, contradicting "WorkOS stays canonical." Apps that want locally-defined flags alongside Dashboard flags use a *different* Pennant store (`array`/`database`) for those and `Feature::store('workos')` for Dashboard ones. |
| D-2 | `set()`, `setForAllScopes()`, `delete()` all **throw** `RuntimeException` ("the workos Pennant store is read-only") | Write-through to WorkOS via `addFlagTarget`/`removeFlagTarget`/`enableFeatureFlag`/`disableFeatureFlag` (a clean 1:1 SDK mapping exists) | The mapping is clean, but `Feature::activate()`/`deactivate()`/`forget()` are ecosystem-standard, "obviously safe/local" verbs everywhere else in Pennant (they mutate a local array/database row). Making them silently mutate a **live, shared WorkOS environment** (e.g., `Feature::deactivateForEveryone('x')` disabling a flag for every customer) is a high-blast-radius surprise hiding behind a familiar-looking call. No success criterion or scope-row line asks for administrative writes in this phase. Throwing is loud, safe, and keeps this phase to its stated read-resolution charter. |
| D-3 | `purge()` and `flushCache()` reset only the in-process "logged unknown slugs" dedupe set; neither touches the Cache-store-backed flag data | Wildcard/tag-based eviction of all `authkit:feature-flags:*` cache entries | Laravel's `array`/`file`/`database` cache drivers (used in this package's own test suite and in many consumer apps) don't support tag-based flushing — a tag-based implementation would silently be a no-op on exactly the stores most likely to be configured. The short (default 30s) freshness TTL already self-heals; there is no correctness reason to force-evict. |
| D-4 | Register `pennant.stores.workos` via a direct `Config::set()` call in **`boot()`**, not `register()` | Setting it in `register()` (matching where `mergeConfigFrom` is normally called) | `laravel/pennant`'s own `PennantServiceProvider::register()` calls `mergeConfigFrom(__DIR__.'/../config/pennant.php', 'pennant')`, which does `Config::set('pennant', array_merge($defaults, $existing))` — an **array_merge at the top-level `pennant` key**, which shallow-overwrites the whole `stores` sub-array. If our provider's `register()` ran *before* Pennant's, our injected `stores.workos` entry would exist alone in `config('pennant')` at merge time, and Pennant's merge would silently drop the built-in `array`/`database` stores for the whole app. Laravel does not guarantee inter-package `register()` ordering (it follows Composer's package-discovery order, not declaration order). Every provider's `register()` is guaranteed to finish before any provider's `boot()` runs, so doing the injection in `boot()` with a **dot-notation** `Config::set('pennant.stores.workos', ...)` (a nested set that only touches the `workos` leaf, never the sibling `array`/`database` keys) eliminates the race entirely, regardless of discovery order. |
| D-5 | `Feature::resolveScopeUsing(fn () => Auth::guard('workos')->user())` registered unconditionally in `boot()` | Rely on Pennant's built-in default (`Auth::guard()->user()` — the *app's default* guard) | "Default scope = current user" means the WorkOS-authenticated user specifically, not whatever the app's ambient `auth.defaults.guard` happens to be. This package should not assume `authkit:install` (Phase 1, a different phase) rewires the app's default guard to `workos`. Setting this explicitly removes that cross-phase coupling. This changes Pennant's default scope resolver app-wide (not just for the `workos` store) — acceptable because an app installing this package treats WorkOS as its auth source of truth; an app that disagrees can call `Feature::resolveScopeUsing()` again in its own `AppServiceProvider::boot()`, which always runs after package providers and therefore wins. |
| D-6 | Scope resolution (`WorkosPennantDriver::resolveResource()`) duck-types the `$scope` value (`Authenticatable` + `workos_id` → user; `Model` + `workos_id` → organization; `"user_…"`/`"org_…"` string → sniffed by prefix; else `null`) instead of requiring `HasWorkosUser`/`HasWorkosOrganization` to implement Pennant's `FeatureScopeable` contract | Have Phase 2/3 add `implements FeatureScopeable` + `toFeatureIdentifier()` to their trait files | Coupling this phase's correctness to another phase's trait *files* (which may not exist yet depending on build order — Organizations, Phase 3, is not even a prereq of this phase) makes this delta non-standalone. Duck-typing on the `workos_id` column that both declared projections (`user link`, `org model`) are contractually guaranteed to have, plus a plain-string escape hatch, keeps this phase fully self-contained and still correct. If Phase 2/3 later add `FeatureScopeable` to those traits, `Decorator::resolveScope()` unwraps it *before* our driver ever sees `$scope`, so both mechanisms compose without conflict. |
| D-7 | Resolution branches on **claims availability + identity match** (`Auth::guard('workos')` authenticated, claims present, claimed subject equals the requested resource), not on `app()->runningInConsole()` | Gate the claims path behind `! app()->runningInConsole()` as the contract note's wording ("authenticated HTTP request" vs "queued jobs, console") literally suggests | Pest/Testbench runs the entire test process as CLI (`PHP_SAPI === 'cli'`), so `runningInConsole()` is `true` for *every* test, including ones simulating an authenticated HTTP request — making the "zero-HTTP claims path" untestable as written. It's also redundant as a signal: a real console command or queued job has no authenticated `workos` guard session unless the app manufactures one, so "claims present and matching" is already `false` in genuine console/queue contexts. Branching on the directly-observable signal (claims) rather than the process-type proxy (`runningInConsole()`) is both more correct (Octane explicitly overrides `runningInConsole()` via `APP_RUNNING_IN_CONSOLE`, so it's not even reliably "false" for real HTTP requests in all deployment topologies) and independently testable. |
| D-8 | Cache key namespaced by a hash of `config('authkit.client_id')`: `authkit:feature-flags:{env-hash}:{type}:{id}` | Plain `authkit:feature-flags:{type}:{id}` | Two environments (staging/prod, or two apps) sharing one Cache store/prefix would otherwise read each other's cached enabled-flag lists — a cross-tenant data leak through the cache, not through WorkOS itself. |
| D-9 | On a WorkOS API failure (after the SDK's own retry/backoff exhausts), serve a stale cached value if one exists (even past the freshness TTL, within a 20×-TTL physical retention window), else fail closed (`false`) | (a) always fail closed, ignoring any stale cache; (b) propagate the exception | Feature flags gate application behavior (often cosmetic — `@feature` in a Blade template, a middleware gate). Propagating an exception turns a WorkOS blip into a 500 for every page that checks a flag — too large a blast radius for a soft gate. Always failing closed even with a perfectly good (if slightly stale) prior answer throws away information for no reason. Serving stale-but-recent data is the standard degraded-mode pattern for this exact class of "is this feature on" check. |

## 4. Components

### 4.1 `WorkosPennantDriver` — the Pennant driver (iterative — feedback loop required)

**Laravel mechanism**: `Laravel\Pennant\Contracts\Driver` implementation, registered via `Feature::extend('workos', ...)`.

**SDK methods wrapped** (exact signatures, read from vendored `workos/workos-php` v9.1.0 source):

```php
// vendor/workos/workos-php/lib/Service/FeatureFlags.php
public function listUserFeatureFlags(
    string $userId,
    ?string $before = null,
    ?string $after = null,
    ?int $limit = null,
    \WorkOS\Resource\PaginationOrder $order = \WorkOS\Resource\PaginationOrder::Desc,
    ?\WorkOS\RequestOptions $options = null,
): \WorkOS\PaginatedResponse; // PaginatedResponse<\WorkOS\Resource\Flag>

public function listOrganizationFeatureFlags(
    string $organizationId,
    ?string $before = null,
    ?string $after = null,
    ?int $limit = null,
    \WorkOS\Resource\PaginationOrder $order = \WorkOS\Resource\PaginationOrder::Desc,
    ?\WorkOS\RequestOptions $options = null,
): \WorkOS\PaginatedResponse;
```

`\WorkOS\Resource\Flag` (returned per page) has a `public string $slug` and `public bool $enabled` (readonly resource). Both list methods return only *enabled* flags for the given resource per the SDK's own doc comments ("Get a list of all enabled feature flags..."); there is no evaluate/check endpoint (confirmed in context brief). `\WorkOS\PaginatedResponse::autoPagingIterator(): \Generator` walks every page.

**Pennant `Driver` contract** (verified from `laravel/pennant` v1.24.0 source, `src/Contracts/Driver.php` — this is the exact, current interface, not a guess):

```php
namespace Laravel\Pennant\Contracts;

interface Driver
{
    public function define(string $feature, callable $resolver): void;
    public function defined(): array;
    public function getAll(array $features): array; // array<string, array<int, mixed>> -> same shape
    public function get(string $feature, mixed $scope): mixed;
    public function set(string $feature, mixed $scope, mixed $value): void;
    public function setForAllScopes(string $feature, mixed $value): void;
    public function delete(string $feature, mixed $scope): void;
    public function purge(?array $features): void;
}
```

Also implements `Laravel\Pennant\Contracts\HasFlushableCache` (`public function flushCache();`) — see Decision D-3 for why this is a near-no-op. Deliberately does **not** implement `CanListStoredFeatures`, `CanSetManyFeaturesForScopes`, or `DefinesFeaturesExternally` — none has a meaningful WorkOS-backed implementation given D-1/D-2 (e.g. `stored()` would have no local storage to enumerate).

**Key design** (full class body — this is the authoritative implementation, not illustrative pseudocode):

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\FeatureFlags;

use Authkit\Authkit\WorkosClientManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Contracts\Driver;
use Laravel\Pennant\Contracts\HasFlushableCache;
use RuntimeException;
use WorkOS\Exception\WorkOSException;

final class WorkosPennantDriver implements Driver, HasFlushableCache
{
    /** Physical cache retention as a multiple of the freshness TTL — long enough to
     *  survive a short WorkOS outage by serving stale data, short enough to self-heal
     *  without manual intervention. See Decision D-9. */
    private const STALE_RETENTION_MULTIPLIER = 20;

    /** @var array<string, true> de-duplicates "unknown/unresolved" log lines per driver-instance lifetime. */
    private array $loggedOnce = [];

    public function __construct(
        private readonly WorkosClientManager $client,
        private readonly CacheRepository $cache,
        private readonly int $cacheTtl,
    ) {}

    public function define(string $feature, callable $resolver): void
    {
        throw new RuntimeException(sprintf(
            'The "workos" Pennant store does not support Feature::define() for "%s". '.
            'Feature flags are defined in the WorkOS Dashboard, not in application code. '.
            'Use a different Pennant store for locally-defined features.',
            $feature,
        ));
    }

    public function defined(): array
    {
        return [];
    }

    public function getAll(array $features): array
    {
        $results = [];

        foreach ($features as $feature => $scopes) {
            $results[$feature] = array_map(
                fn (mixed $scope): mixed => $this->get($feature, $scope),
                $scopes,
            );
        }

        return $results;
    }

    public function get(string $feature, mixed $scope): mixed
    {
        $resource = $this->resolveResource($scope);

        if ($resource === null) {
            $this->logOnce("no-resource:{$feature}", "authkit.feature_flags: no resolvable WorkOS user/organization for feature [{$feature}]; defaulting to false.");

            return false;
        }

        $fromClaims = $this->resolveFromClaims($feature, $resource);

        if ($fromClaims !== null) {
            return $fromClaims;
        }

        $enabledSlugs = $this->enabledSlugsFor($resource);
        $active = in_array($feature, $enabledSlugs, true);

        if (! $active) {
            $this->logOnce(
                "unknown:{$feature}:{$resource->type}",
                "authkit.feature_flags: [{$feature}] resolved to false for {$resource->type} [{$resource->id}] ".
                '(not enabled for this scope, or the slug does not exist in this WorkOS environment).',
            );
        }

        return $active;
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        throw new RuntimeException(
            'The "workos" Pennant store is read-only. Feature flags are managed in the WorkOS Dashboard '.
            'or via the WorkOS API directly; Feature::activate()/deactivate() are not supported here.',
        );
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        throw new RuntimeException(
            'The "workos" Pennant store is read-only. Feature::activateForEveryone()/deactivateForEveryone() '.
            'are not supported here — toggle the flag in the WorkOS Dashboard.',
        );
    }

    public function delete(string $feature, mixed $scope): void
    {
        throw new RuntimeException('The "workos" Pennant store is read-only; Feature::forget() is not supported here.');
    }

    public function purge(?array $features): void
    {
        $this->loggedOnce = [];
    }

    public function flushCache(): void
    {
        // Deliberately does not touch the Cache-store-backed flag data — see Decision D-3.
        $this->loggedOnce = [];
    }

    private function resolveResource(mixed $scope): ?WorkosFeatureScope
    {
        return match (true) {
            $scope === null => null,
            is_string($scope) && str_starts_with($scope, 'org_') => new WorkosFeatureScope('organization', $scope),
            is_string($scope) && str_starts_with($scope, 'user_') => new WorkosFeatureScope('user', $scope),
            $scope instanceof Authenticatable && filled($scope->workos_id ?? null) => new WorkosFeatureScope('user', (string) $scope->workos_id),
            $scope instanceof Model && filled($scope->workos_id ?? null) => new WorkosFeatureScope('organization', (string) $scope->workos_id),
            default => null,
        };
    }

    private function resolveFromClaims(string $feature, WorkosFeatureScope $resource): ?bool
    {
        $claims = $this->guardClaims();

        if ($claims === null) {
            return null; // no authenticated workos session this request — API path
        }

        $subject = $resource->type === 'user' ? ($claims['sub'] ?? null) : ($claims['org_id'] ?? null);

        if ($subject !== $resource->id) {
            return null; // checking someone other than the current session's principal — API path
        }

        if (! array_key_exists('feature_flags', $claims)) {
            return null; // claim absent/truncated (4KB cookie ceiling) — API path
        }

        return in_array($feature, $claims['feature_flags'], true);
    }

    /** @return array<string, mixed>|null */
    private function guardClaims(): ?array
    {
        $guard = Auth::guard('workos');

        if (method_exists($guard, 'claims')) {
            return $guard->claims();
        }

        $user = $guard->user();

        if ($user !== null && method_exists($user, 'workosClaims')) {
            return $user->workosClaims();
        }

        return null;
    }

    /** @return list<string> */
    private function enabledSlugsFor(WorkosFeatureScope $resource): array
    {
        $key = $this->cacheKey($resource);
        $cached = $this->cache->get($key);

        if (is_array($cached) && (time() - $cached['cachedAt']) < $this->cacheTtl) {
            return $cached['slugs'];
        }

        try {
            $slugs = $this->fetchFromApi($resource);
        } catch (WorkOSException $e) {
            if (is_array($cached)) {
                Log::warning(
                    "authkit.feature_flags: WorkOS unreachable; serving flags cached ".
                    (time() - $cached['cachedAt'])."s ago for {$resource->type} [{$resource->id}].",
                    ['exception' => $e],
                );

                return $cached['slugs'];
            }

            Log::error(
                "authkit.feature_flags: WorkOS unreachable and no cached flags for {$resource->type} ".
                "[{$resource->id}]; defaulting every flag to false for this scope.",
                ['exception' => $e],
            );

            return [];
        }

        $this->cache->put($key, ['slugs' => $slugs, 'cachedAt' => time()], now()->addSeconds($this->cacheTtl * self::STALE_RETENTION_MULTIPLIER));

        return $slugs;
    }

    /** @return list<string> */
    private function fetchFromApi(WorkosFeatureScope $resource): array
    {
        $service = $this->client->client()->featureFlags();

        $response = match ($resource->type) {
            'user' => $service->listUserFeatureFlags($resource->id),
            'organization' => $service->listOrganizationFeatureFlags($resource->id),
        };

        $slugs = [];

        foreach ($response->autoPagingIterator() as $flag) {
            $slugs[] = $flag->slug;
        }

        return $slugs;
    }

    private function cacheKey(WorkosFeatureScope $resource): string
    {
        $env = substr(md5((string) config('authkit.client_id')), 0, 8);

        return "authkit:feature-flags:{$env}:{$resource->type}:{$resource->id}";
    }

    private function logOnce(string $dedupeKey, string $message): void
    {
        if (isset($this->loggedOnce[$dedupeKey])) {
            return;
        }

        $this->loggedOnce[$dedupeKey] = true;

        Log::debug($message);
    }
}
```

**Implementation steps**:

1. `php artisan make:class FeatureFlags/WorkosFeatureScope --pure` is not a Laravel generator (no such stub exists for plain DTOs); create `src/FeatureFlags/WorkosFeatureScope.php` and `src/FeatureFlags/WorkosPennantDriver.php` directly — there is no `make:` generator for either (no migration, model, controller, or job is involved). This matches the contract's own instruction to prefer generators "where applicable"; neither file is a generator-shaped artifact.
2. Add `laravel/pennant` to `composer.json` `require` (see §1).
3. Write `WorkosFeatureScope` (readonly DTO, §4.3).
4. Write `WorkosPennantDriver` exactly as above.
5. Wire registration in `AuthkitServiceProvider::boot()` (§4.2, §6).
6. Add the `feature_flags` config block to `config/authkit.php` (§5).
7. Write `tests/Feature/FeatureFlagsTest.php` (§7).

**Feedback loop**:
- **Playground**: `composer serve` (Testbench workbench app) with `WorkosClientManager`'s Guzzle handler swapped for a `MockHandler` inside `workbench/app/Providers/WorkbenchServiceProvider.php` for local iteration (mirrors the template's MockHandler test-path helper from Phase 1, used interactively instead of in a test); then `php artisan tinker` against the booted workbench app: `Feature::for($user)->active('some-flag')`.
- **Parameterized experiment**: a scratch Pest test (not committed, or committed as the first draft of §7's suite) that runs the same `get()` call across the matrix — {claims present/absent} × {identity match/mismatch} × {slug present/absent in returned list} × {API success/failure with/without warm cache} — re-run on every edit to the driver.
- **Check command**: `vendor/bin/pest --filter=FeatureFlags` — seconds, scoped to this suite, MockHandler-backed (no network, no emulate boot).

### 4.2 Pennant Store Registration & Default Scope (iterative — feedback loop required)

**Laravel mechanism**: `AuthkitServiceProvider::boot()` additions — `Feature::extend()`, a `Config::set()` injection into `laravel/pennant`'s own config namespace, and `Feature::resolveScopeUsing()`.

**Key design**: see §6 for the exact diff. The behavior under test is *not* "does the code run" but "does `Feature::store('workos')` resolve without `InvalidArgumentException`, under both a normal boot and a `config:cache`'d boot, regardless of provider registration order" — this is precisely the race described in Decision D-4.

**Feedback loop**:
- **Playground**: `php artisan tinker` inside the workbench app → `config('pennant.stores')` to inspect the merged array; `Feature::store('workos')` to confirm it resolves.
- **Parameterized experiment**: toggle `php artisan config:cache` on and off, re-running the same tinker checks each time; separately, temporarily reorder `extra.laravel.providers` in `composer.json` (or register a dummy competing provider that also touches `pennant.stores` in its own `register()`) to confirm boot()-time registration is order-independent.
- **Check command**: `vendor/bin/pest --filter=FeatureFlags` (includes the `ConfigCache`-style case from §7); this phase does not need a *new* global `--filter=ConfigCache` suite — it adds one case to its own area suite, consistent with the project-wide `ConfigCache` success criterion being validated holistically in Phase 13.

### 4.3 `WorkosFeatureScope` DTO (trivial — feedback loop skipped)

```php
<?php

declare(strict_types=1);

namespace Authkit\Authkit\FeatureFlags;

final readonly class WorkosFeatureScope
{
    public function __construct(
        public string $type, // 'user' | 'organization'
        public string $id,   // WorkOS ID, e.g. "user_01H..." or "org_01H..."
    ) {}
}
```

No feedback loop per the task's own instruction: this is a plain immutable value object with no branching logic of its own — correctness is fully exercised through `WorkosPennantDriver`'s suite.

## 5. File Changes

### New

| Path | Purpose | Traces to |
|---|---|---|
| `src/FeatureFlags/WorkosPennantDriver.php` | Pennant `Driver` + `HasFlushableCache` implementation | Scope row: "Feature Flags: first-party laravel/pennant driver..." |
| `src/FeatureFlags/WorkosFeatureScope.php` | Normalized {type, id} scope value object | Same scope row — supports the scope-mapping requirement in the phase direction |
| `tests/Feature/FeatureFlagsTest.php` | MockHandler-backed Pest suite for this area | Success criterion "Feature flags resolve in both contexts..."; contract check `ls tests/Feature/*Test.php \| wc -l — ≥16` |

### Modified

| Path | Change | Traces to |
|---|---|---|
| `composer.json` | Add `"laravel/pennant": "^1.22"` to `require` | Dependency decision, §1 |
| `src/AuthkitServiceProvider.php` | Add `registerFeatureFlagsDriver(): void` call in `boot()` (before the `runningInConsole()` early-return) — see §6 for exact diff | Scope row; Decisions D-4, D-5 |
| `config/authkit.php`* | Add `feature_flags => ['cache_ttl' => 30]` block | Phase direction: "short TTL cache (config default 30s per WorkOS runtime-client guidance)" |

\* This delta assumes Phase 1 has already renamed `config/authkit-laravel.php` → `config/authkit.php` per the template's canonical convention and the context brief. At the time this repo snapshot was inspected, the file was still `config/authkit-laravel.php` with only a `placeholder` key — Phase 1 owns the rename; this phase's config addition targets whatever `config/authkit.php` looks like once Phase 1 lands, additively (no removal of Phase 1's own keys).

### Explicitly not changed (documented, not an oversight)

| Path | Why not |
|---|---|
| `workbench/app/Models/User.php` | Already carries `workos_id` by the time this phase runs (Phase 2's declared projection) — this driver reads it via `Authenticatable` duck-typing (Decision D-6), no trait/file coupling needed |
| Any workbench Organization model | Organizations (Phase 3) is **not** a prereq of this phase; the org-scope resolution path is exercised in this phase's own test suite against an inline test-double model (§7), not a real workbench model. Workbench demonstration of `@feature`/`Feature::active()` usage is deferred to Phase 13's holistic workbench build-out. |
| `database/migrations/*` | No new local WorkOS-shaped state — flags are cached, not projected (contract's projection-boundary doctrine) |
| `routes/*.php` | No HTTP surface of its own; Pennant's own `@feature`/`@featureany` Blade directives and middleware ship from `laravel/pennant` itself, unmodified |

## 6. Service Provider Registration Diff

```php
// src/AuthkitServiceProvider.php

use Authkit\Authkit\FeatureFlags\WorkosPennantDriver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;

public function boot(): void
{
    $this->loadRoutesFrom(__DIR__.'/../routes/authkit-laravel.php');
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'authkit-laravel');
    $this->loadTranslationsFrom(__DIR__.'/../lang', 'authkit-laravel');

    $this->registerFeatureFlagsDriver();

    if (! $this->app->runningInConsole()) {
        return;
    }

    // ...existing publishes()/publishesMigrations()/commands() unchanged...
}

private function registerFeatureFlagsDriver(): void
{
    // Dot-notation nested set — only touches the "workos" leaf, never the sibling
    // "array"/"database" store entries laravel/pennant's own mergeConfigFrom adds
    // in ITS register(). Must run in boot(), not register() — see Decision D-4.
    $this->app['config']->set('pennant.stores.workos', array_merge(
        ['driver' => 'workos'],
        $this->app['config']->get('pennant.stores.workos', []),
    ));

    Feature::extend('workos', fn ($container, array $config) => new WorkosPennantDriver(
        $container->make(WorkosClientManager::class),
        $container->make(CacheRepository::class),
        (int) $this->app['config']->get('authkit.feature_flags.cache_ttl', 30),
    ));

    // Default scope = the WorkOS-authenticated user, not the app's ambient default
    // guard — see Decision D-5. An app-level provider booting after this one may
    // override it again.
    Feature::resolveScopeUsing(fn () => Auth::guard('workos')->user());
}
```

`Feature::extend()`'s closure signature (`function ($container, array $config)`) is taken verbatim from `Laravel\Pennant\FeatureManager::callCustomCreator()` (v1.24.0 source: `return $this->customCreators[$config['driver']]($this->container, $config);`), not guessed.

## 7. Testing Requirements

**Suite**: `tests/Feature/FeatureFlagsTest.php` — **MockHandler-backed** per the phase direction ("emulate flag endpoints have verb mismatches — do not rely on them") and the template's own test-path table (feature-flag reads are listed as "verb mismatch — verify before relying" under emulate coverage). Never boots `workos/emulate` for this suite.

**Test-only doubles** (defined at the top of the test file, not shipped in `src/`):
- A minimal `StubWorkosGuard` implementing `Illuminate\Contracts\Auth\Guard` plus a `claims(): ?array` method, registered via `Auth::extend('workos', fn () => new StubWorkosGuard(...))`. This decouples the suite from Phase 2's real sealed-session guard internals (Decision D-7's rationale) while still pinning the exact contract this driver depends on: *any* guard registered as `workos` that optionally exposes `claims()`.
- A tiny in-memory `class TestOrganization extends Illuminate\Database\Eloquent\Model { protected $fillable = ['workos_id']; }` (no migration — never persisted, only used as a scope value) to exercise the organization branch of `resolveResource()` without depending on Phase 3's real Organization model.

**Cases**:

1. `it resolves via claims when the workos guard has a matching authenticated session, zero HTTP` — stub guard returns claims with `sub` = user's `workos_id` and `feature_flags = ['new-dashboard']`; `Feature::for($user)->active('new-dashboard')` is `true`; `Feature::for($user)->active('other-flag')` is `false`; MockHandler has **zero** queued responses (a stray HTTP call throws, failing the test).
2. `it falls back to the API when no workos session is authenticated` (console/queue equivalent per Decision D-7) — no `actingAs`; MockHandler queued with a `listUserFeatureFlags` response containing the slug; `Feature::for($user)->active('flag')` is `true`; asserts the mock queue was consumed.
3. `it falls back to the API when checking a scope other than the current session's principal` — stub guard authenticated as user A with claims; `Feature::for($userB)->active('flag')` (different `workos_id`) still hits the MockHandler-queued response rather than trusting A's claims.
4. `it falls back to the API when the feature_flags claim is absent` — stub guard claims omit the `feature_flags` key entirely (simulating 4KB truncation); falls through to API.
5. `it resolves organization scope via listOrganizationFeatureFlags` — `Feature::for($testOrganization)->active('flag')` with a queued `organizations/{id}/feature-flags` response.
6. `it returns false and logs once per slug for an unknown or disabled flag` — `Log::spy()`; two consecutive checks of the same missing slug on the same driver instance produce exactly one `Log::debug` call.
7. `it serves stale cached flags when WorkOS is unreachable and a prior successful fetch exists` — first call succeeds and populates the cache; second call's MockHandler response is a 500/`ConnectionException`; asserts the previously-cached value is still returned and `Log::warning` fired.
8. `it returns false and logs an error when WorkOS is unreachable with no prior cache` — first call ever for that scope fails; asserts `false` and `Log::error`.
9. `it throws for Feature::define() on the workos store` — `Feature::store('workos')->define('x', fn () => true)` throws `RuntimeException`.
10. `it throws for activate/deactivate/forget on the workos store` — `Feature::store('workos')->activate('x')`, `->deactivateForEveryone('x')`, `->forget('x')` each throw.
11. `it resolves the workos Pennant store without InvalidArgumentException` — plain `Feature::store('workos')` resolves regardless of whether `config()->set('pennant.stores.workos', ...)` ran before or after `PennantServiceProvider::register()` in this test's boot order (covers Decision D-4 directly, doubling as this suite's config-cache-safety case per §4.2's feedback loop).
12. `it does not evict cached flag data when flushCache runs` — populate cache, call `app(FeatureManager::class)->flushCache()`, assert a subsequent check within the TTL window still returns the cached value without a second HTTP call (covers Decision D-3 / the Octane failure mode named in §8).

**Seed data**: none (no emulate, no database rows) — every case seeds its own MockHandler queue and stub-guard claims inline.

## 8. Failure Modes

| Named failure | Trigger | Detection | Mitigation |
|---|---|---|---|
| Cross-Scope Claim Leakage | Checking a flag for a user/org other than the current session's principal | Resolved resource identity (`workos_id`) vs. claims' `sub`/`org_id` | `resolveFromClaims()` returns `null` (→ API path) on any identity mismatch; claims are only ever trusted for the exact principal they describe |
| Truncated/Missing `feature_flags` Claim | WorkOS's 4KB sealed-cookie ceiling drops the claim when a token carries many flags/permissions (documented WorkOS behavior) | `array_key_exists('feature_flags', $claims)` is `false` | Falls back to the API path automatically; never treated as "flag off" |
| Unresolvable Scope | `$scope` is `null`, or a model without a `workos_id`, or an unrecognized type | `resolveResource()` returns `null` | Returns `false`, logs once (debug) per feature — an `@feature` check degrades to "off" instead of throwing and breaking the page |
| Unknown or Disabled Flag Slug | Slug absent from the resolved enabled-set (typo, wrong environment, or legitimately off) — cannot be distinguished from "genuinely off" without an extra admin lookup this driver deliberately does not make | Slug not `in_array()` of claims/API result | `false` + one `debug`-level log line per slug per driver-instance lifetime (deduped via `$loggedOnce`) |
| WorkOS Unreachable, Cold Cache | API down/5xx after the SDK's own retry/backoff exhausts, no prior fetch for that scope | `WorkOSException` caught, `$cache->get()` returned nothing usable | Fail closed (`false` for every flag in that scope); `error`-level log **every** occurrence (not deduped — ops needs every incident) |
| WorkOS Unreachable, Warm Cache | Same, but a prior successful fetch exists (within the 20×-TTL retention window) | Same exception, but a cached array is present | Serve the stale list; `warning`-level log every occurrence |
| Stale Claims Between Session Refreshes | A Dashboard flag is toggled mid-session; the sealed-session JWT still carries the old `feature_flags` claim until Phase 2's refresh middleware naturally rotates it | Not directly detectable by this driver | **Accepted, bounded staleness per contract doctrine — not engineered around.** No forced per-check session refresh is added. |
| Cache-Key Collision Across WorkOS Environments | Two environments/apps sharing one Cache store/prefix | N/A (prevention, not detection) | Cache key namespaced by a hash of `config('authkit.client_id')` (Decision D-8) |
| Pennant Store Registration Order Race | `pennant.stores.workos` set in `register()` instead of `boot()`; Composer's package-discovery order between this package and `laravel/pennant` is unspecified | `Feature::store('workos')` throws `InvalidArgumentException`, or the app's built-in `array`/`database` stores silently disappear | Register in `boot()` with a dot-notation `Config::set()` (Decision D-4) — covered by test case 11 |
| Octane `flushCache()` Defeats the TTL Cache | Pennant's own `RequestReceived`/`TaskReceived`/`JobProcessed` listeners call `flushCache()` on every `HasFlushableCache` store on every request/job under Octane | Cache-store hit rate would drop to zero under Octane if `flushCache()` cleared it | `flushCache()` only resets the logged-slug dedupe set, never the Cache-store-backed flag data (Decision D-3) — covered by test case 12 |
| Emulate's Feature-Flag Verb Mismatch | `workos/emulate`'s flag endpoints don't match the documented HTTP verbs | N/A — a test-path constraint, not a runtime failure | This phase's suite is MockHandler-only, never emulate; documented local-DX gap, not a bug in this driver |
| Concurrent Refresh Stampede | Many requests miss the cache simultaneously at the TTL boundary under load, each independently calling the API for the same scope | N/A | **Accepted at v1 scale** (mirrors the contract's FGA no-cache-without-invalidation precedent) — no distributed lock added; short TTL bounds the blast radius |
| Idempotency (Retry / Double-Dispatch) | A caller retries or double-dispatches any call into this driver | N/A — structurally moot, not runtime-detected | `get()`, `getAll()`, and `defined()` are pure reads against WorkOS's list-feature-flags endpoints (`listUserFeatureFlags`/`listOrganizationFeatureFlags`) — re-running a read produces the same answer, never a duplicate. `set()`, `setForAllScopes()`, and `delete()` throw `RuntimeException` before any request is made (Decision D-2) — there is no side effect to duplicate. `purge()` and `flushCache()` only reset the in-process logged-slug dedupe set (Decision D-3) — a no-op safe to call any number of times. No method in this driver has a mutating side effect a retry could replay. |

## 9. Deviations from the Template

None. Test path (MockHandler-only, no emulate) matches the template's own designation for this area. Facade surface stays within the template's `Authkit`-only convention — this phase adds no new facade.

## 10. Validation Commands

```bash
composer analyse                        # PHPStan (larastan)
composer lint:check                     # Pint check-only
composer test:types                     # Pest type coverage --min=100
vendor/bin/pest --filter=FeatureFlags   # this phase's suite — seconds
composer test                           # full chain — must be green before commit
```

## Open Items (cross-phase interface assumptions — confirm at implementation time)

1. **`WorkosClientManager::client(): \WorkOS\WorkOS`** — this delta assumes Phase 1's client manager exposes a single `client()` accessor returning the constructed SDK instance. Phase 1's actual method name may differ; if so, update the one call site in `WorkosPennantDriver::fetchFromApi()` (`$this->client->client()->featureFlags()`) accordingly — no other design in this phase depends on the exact name.
2. **`Auth::guard('workos')->claims(): ?array`** — this delta assumes the `workos` guard (Phase 2) optionally exposes a `claims()` method beyond the base `Guard` contract (idiomatic for custom guards — e.g. Sanctum's guard adds `currentAccessToken()`). `guardClaims()` also tries a `workosClaims()` method on the resolved user as a fallback. If Phase 2 lands with neither convention, `guardClaims()` always returns `null` and this driver silently (and correctly, just not optimally) always takes the API-fallback path — a functional degradation, not a break, but worth confirming against Phase 2's real implementation so the zero-HTTP path actually engages in production.
3. **`config('authkit.client_id')`** — assumed config key name mirroring the `WORKOS_CLIENT_ID` env var, used only as a low-stakes cache-key namespacing discriminator (Decision D-8). If Phase 1 names this key differently, only the cache-key format changes; no correctness impact either way (worst case: cache entries are namespaced by an empty string, which is still internally consistent for a single-environment app and only matters for the specific multi-environment-sharing-one-cache-store scenario D-8 defends against).
4. **`config/authkit.php`** vs. today's actual `config/authkit-laravel.php`** — this delta targets the post-Phase-1-rename filename per the template's canonical convention; the config addition is additive either way.
