# Implementation Spec: Platform Parity - Phase 4 (Feature Flags)

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Phase 1 extracts `feature_flags` from the JWT session response and exposes `WorkOSSession::hasFeatureFlag()`. Phase 4 adds the full Feature Flags API integration: a `FeatureFlagService` accessible via `WorkOS::flags()` that wraps `Organizations::listOrganizationFeatureFlags()`, `workos.feature` middleware for route-level gating, and a `@workosFeature` Blade directive (if not already registered by Phase 1).

**SDK reality check**: The WorkOS PHP SDK does not have a standalone `FeatureFlags` service class. Feature flag API access is on `Organizations::listOrganizationFeatureFlags()`, which returns a `PaginatedResource` of `FeatureFlag` objects (`id`, `slug`, `name`, `description`). The session response provides `feature_flags` as an array of `FeatureFlag` objects (not plain strings). Phase 1's `WorkOSSession::hasFeatureFlag()` must match against the `slug` field of these objects — this spec assumes Phase 1 has been updated accordingly. If not, Phase 4 must fix the matching logic before adding the API layer.

**JWT-first strategy**: `WorkOS::hasFeatureFlag()` checks the session's `featureFlags` array first (zero API calls). Only when no session exists — or when explicitly asked for the full flag list — does it call the API. The `workos.feature` middleware follows this same priority.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/FeatureFlagServiceTest.php tests/Feature/CheckFeatureFlagTest.php`

**Playground**: Test suite. The `FeatureFlagService` wraps the Organizations SDK method, so unit tests mock `\WorkOS\Organizations` directly.

**Why this approach**: All middleware and service logic is pure PHP with mockable SDK dependencies. No browser or external call needed in the test loop.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/FeatureFlags/FeatureFlagService.php` | Service wrapping `Organizations::listOrganizationFeatureFlags()` with session-first shortcut |
| `src/Http/Middleware/CheckFeatureFlag.php` | `workos.feature` middleware — blocks route when flag is off |
| `tests/Unit/FeatureFlagServiceTest.php` | Unit tests for service logic |
| `tests/Feature/CheckFeatureFlagTest.php` | Feature tests for middleware behavior |

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOS.php` | Add `flags()` method returning `FeatureFlagService`; add `hasFeatureFlag()` if not already present from Phase 1 |
| `src/Facades/WorkOS.php` | Add `@method` docblock entries for `flags()` and `hasFeatureFlag()` |
| `src/WorkOSServiceProvider.php` | Register `workos.feature` middleware alias; register `@workosFeature` Blade directive if not done in Phase 1 |
| `config/workos.php` | Add `features.feature_flags` toggle (default `true`) |
| `tests/Unit/WorkOSSessionTest.php` | Verify `hasFeatureFlag()` matches on slug (regression guard for Phase 1 object format) |

## Implementation Details

### FeatureFlagService

**Pattern to follow**: `src/Audit/AuditLogger.php` (constructor-injected SDK dependency, single-responsibility service)

**Overview**: Thin wrapper around the Organizations SDK method. Responsible for translating between the SDK's `FeatureFlag` resource objects and the simple slug-based checks the rest of the package uses.

```php
namespace WorkOS\AuthKit\FeatureFlags;

use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\Organizations;

class FeatureFlagService
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly Organizations $organizations,
    ) {}

    public function isEnabled(string $slug, ?string $organizationId = null): bool
    {
        // JWT-first: check session featureFlags before calling the API
        $session = $this->session->getSession();
        if ($session !== null) {
            return $session->hasFeatureFlag($slug);
        }

        // No session: fall back to API if org ID is available
        $orgId = $organizationId ?? $this->session->getOrganizationId();
        if ($orgId === null) {
            return false;
        }

        return $this->flagEnabledViaApi($slug, $orgId);
    }

    /**
     * @return array<\WorkOS\Resource\FeatureFlag>
     */
    public function listForOrganization(string $organizationId): array
    {
        $result = $this->organizations->listOrganizationFeatureFlags($organizationId);

        return $result->feature_flags ?? [];
    }

    private function flagEnabledViaApi(string $slug, string $organizationId): bool
    {
        try {
            $flags = $this->listForOrganization($organizationId);

            foreach ($flags as $flag) {
                if ($flag->slug === $slug) {
                    return true;
                }
            }

            return false;
        } catch (\Exception) {
            return false;
        }
    }
}
```

**Key decisions**:
- Session check is always first — avoids API calls on every request
- API fallback only triggers when there is no session AND an org ID is resolvable
- Silent exception catch on the API fallback — flag checks must never crash a page render
- The service is registered as a singleton; the `Organizations` SDK instance is shared via the `WorkOS` service's instance cache

### WorkOS::flags()

```php
public function flags(): FeatureFlagService
{
    return $this->instances['flags'] ??= new FeatureFlagService(
        $this->session,
        $this->organizations(),
    );
}
```

The `hasFeatureFlag()` method (added in Phase 1) delegates to `$this->session->hasFeatureFlag()`. It does **not** call `flags()->isEnabled()` by default to keep the cost predictable — callers who want API fallback use `WorkOS::flags()->isEnabled()` explicitly.

### CheckFeatureFlag Middleware

**Pattern to follow**: `src/Http/Middleware/CheckPermission.php`

```php
namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;

class CheckFeatureFlag
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        foreach ($flags as $flag) {
            if (! $this->flags->isEnabled($flag)) {
                abort(403, "Feature [{$flag}] is not enabled.");
            }
        }

        return $next($request);
    }
}
```

Usage:
```php
Route::get('/new-dashboard', NewDashboardController::class)
    ->middleware('workos.feature:new-dashboard');

// Multiple flags (all must be enabled):
Route::get('/beta', BetaController::class)
    ->middleware('workos.feature:new-dashboard,advanced-reporting');
```

**Key decision**: Multiple flags use variadic `string ...$flags` matching the `CheckPermission` pattern. All listed flags must be enabled (AND semantics). Abort with 403 rather than redirect — the caller controls error handling via Laravel's exception handler.

### Blade Directive

If Phase 1 did not register `@workosFeature`, register it in `WorkOSServiceProvider::configureBladeDirectives()`:

```php
Blade::if('workosFeature', fn (string $flag) => app(FeatureFlagService::class)->isEnabled($flag));
```

This uses `FeatureFlagService::isEnabled()` rather than calling `WorkOS::hasFeatureFlag()` directly so it benefits from the API fallback for server-side rendering contexts where no session token is present.

### Config

```php
// In config/workos.php, under 'features':
'feature_flags' => env('WORKOS_FEATURE_FLAGS', true),
```

The `workos.feature` middleware and `@workosFeature` directive check this toggle and short-circuit to `false` when disabled. This lets applications opt out of all feature flag checking without removing individual middleware registrations.

### Service Registration

```php
// In WorkOSServiceProvider::register():
$this->app->singleton(FeatureFlagService::class, function ($app) {
    return new FeatureFlagService(
        $app->make(SessionManager::class),
        new \WorkOS\Organizations,
    );
});
```

```php
// In WorkOSServiceProvider::configureMiddleware():
$router->aliasMiddleware('workos.feature', CheckFeatureFlag::class);
```

## Implementation Details: Session Flag Format

The SDK's `SessionAuthenticationSuccessResponse` constructs `featureFlags` as an array of `FeatureFlag` objects, each with a `slug` property. Phase 1's `WorkOSSession` stores them. The `hasFeatureFlag(string $slug)` method must compare against `$flag->slug`, not a plain string.

If Phase 1 stored them as plain strings (slugs only), Phase 4 must audit and fix the `WorkOSSession::fromAuthResponse()` and `fromArray()` factories to either:
- Store full `FeatureFlag` objects (preferred — preserves `name`, `description` for display)
- Store slugs only (simpler, loses metadata)

This spec recommends storing slugs only in `WorkOSSession::$featureFlags` for simplicity, and using `FeatureFlagService` when full flag metadata is needed. The `fromAuthResponse()` factory should extract slugs from the SDK objects:

```php
featureFlags: isset($response['feature_flags']) && is_array($response['feature_flags'])
    ? array_map(fn ($f) => is_object($f) ? $f->slug : (string) $f, $response['feature_flags'])
    : [],
```

## Testing Requirements

### Unit Tests

**File**: `tests/Unit/FeatureFlagServiceTest.php`

**Key test cases**:
- `isEnabled()` returns `true` when flag slug is in session `featureFlags`
- `isEnabled()` returns `false` when flag is not in session
- `isEnabled()` calls Organizations API when no session exists and org ID is available
- `isEnabled()` returns `false` when no session and no org ID
- `isEnabled()` returns `false` and does not throw when API call fails
- `listForOrganization()` delegates to Organizations SDK and returns array of flags
- Feature flag checking is disabled when `workos.features.feature_flags` is `false`

**File**: `tests/Unit/WorkOSSessionTest.php` (additions)

- `hasFeatureFlag()` matches by slug when `featureFlags` contains slugs
- `hasFeatureFlag()` returns `false` for unknown slug

### Feature Tests

**File**: `tests/Feature/CheckFeatureFlagTest.php`

**Key test cases**:
- Route with `workos.feature:flag-slug` returns 200 when flag is enabled in session
- Route with `workos.feature:flag-slug` returns 403 when flag is disabled
- Route with multiple flags requires all to be enabled
- Unauthenticated user gets 403 when flag is required (no session, no org fallback)
- `@workosFeature('flag-slug')` Blade directive renders content when flag is on
- `@workosFeature('flag-slug')` Blade directive hides content when flag is off

## Validation Commands

```bash
composer analyse
composer test
```
