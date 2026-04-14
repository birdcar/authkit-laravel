# Implementation Spec: WorkOS SDK v5 Migration - Phase 1

**Contract**: ./contract.md
**Estimated Effort**: XL

## Technical Approach

Phase 1 replaces the foundational SDK integration layer. The v4 static configuration model (`WorkOS::setApiKey()`, `new UserManagement()`) is replaced with v5's instantiated client (`new \WorkOS\WorkOS(apiKey:, clientId:)`). Every downstream phase depends on this — the v5 client singleton must be in the container before any service can use it.

The key architectural change is that `WorkOS.php` no longer instantiates SDK service classes directly. Instead, it holds a reference to the v5 `\WorkOS\WorkOS` client and delegates all service access through it. The `SERVICE_MAP` constant and `__call()` magic method are removed entirely — every service gets an explicit typed accessor.

This phase also updates `WorkOSFake` enough that the test infrastructure compiles. Full test adaptation happens in later phases alongside the code they test.

## Feedback Strategy

**Inner-loop command**: `composer analyse`

**Playground**: PHPStan — since this phase is mostly type-level rewiring (changing class references, method signatures, return types), static analysis is the tightest feedback loop.

**Why this approach**: Most changes are swapping SDK class references. PHPStan level 8 catches type mismatches immediately without needing to run the full test suite.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `composer.json` | Change `workos/workos-php` from `^4.29` to `^5.0` |
| `src/WorkOSServiceProvider.php` | Replace `configureWorkOSSdk()` static calls with v5 client instantiation; update all singleton registrations that construct SDK classes directly |
| `src/WorkOS.php` | Complete rewrite: remove SERVICE_MAP, remove __call(), inject v5 client, add explicit accessors for all 22+ v5 services, replace validateApiKey() manual HTTP, update loginUrl()/signUpUrl() for v5 SSO changes |
| `src/Facades/WorkOS.php` | Update all `@method` docblocks to reference v5 return types and new service accessors |
| `src/Testing/WorkOSFake.php` | Update to match new `WorkOS.php` method signatures; stub new service accessors to prevent type errors |
| `src/helpers.php` | Update `workos()` return type hint if needed |

### Deleted Files

_None — files are modified in place._

## Implementation Details

### 1. Composer Dependency Update

**Overview**: Bump the SDK constraint and resolve any transitive dependency issues.

**Implementation steps**:

1. Change `"workos/workos-php": "^4.29"` to `"workos/workos-php": "^5.0"` in `composer.json`
2. Run `composer update workos/workos-php --with-all-dependencies`
3. Verify Halite 5.1 resolves (required by SDK v5); our PHP 8.3 constraint already satisfies the v5 minimum of 8.2
4. Verify `composer.lock` updates cleanly — no conflicts with illuminate packages

**Failure Modes**:

| Failure | Trigger | Mitigation |
|---|---|---|
| Halite version conflict | Other package pins Halite 4.x | Check `composer why paragonie/halite` — may need to update conflicting package |
| PHP extension missing | v5 declares `ext-curl:^8.2` | Already present on PHP 8.3; verify with `php -m` |

### 2. WorkOSServiceProvider — v5 Client Instantiation

**Pattern to follow**: Current `configureWorkOSSdk()` at `src/WorkOSServiceProvider.php:197-214`

**Overview**: Replace the static `\WorkOS\WorkOS::setApiKey()` / `\WorkOS\WorkOS::setClientId()` calls with a `\WorkOS\WorkOS` client instance registered as a singleton. This client becomes the single source of SDK access.

**Implementation steps**:

1. Register a `\WorkOS\WorkOS` singleton (the SDK client) in `register()`:
   ```php
   $this->app->singleton(\WorkOS\WorkOS::class, function () {
       $apiKey = config('workos.api_key');
       $clientId = config('workos.client_id');
       // ... validation ...
       return new \WorkOS\WorkOS(
           apiKey: $apiKey,
           clientId: $clientId,
       );
   });
   ```
2. Update the `'workos'` singleton to accept the SDK client:
   ```php
   $this->app->singleton('workos', function ($app) {
       return new WorkOS(
           $app->make(\WorkOS\WorkOS::class),
           $app->make(SessionManager::class),
       );
   });
   ```
3. Remove `configureWorkOSSdk()` method entirely
4. Update `FeatureFlagService` singleton — remove `new \WorkOS\Organizations` direct construction, instead get it from the SDK client
5. Update `AuditLogger` singleton — remove `new AuditLogs` direct construction
6. Update `FGAService` singleton — it currently uses `WorkOS\Client` internals, but the constructor just takes `SessionManager`, so no change needed here (Phase 4 rewrites the service itself)

**Key decisions**:
- The SDK client is registered as `\WorkOS\WorkOS::class`, not as a named binding, because downstream code should not depend on it directly — they go through our `WorkOS` service class
- Validation of API key/client ID stays in the singleton factory (lazy), not in `register()`

**Feedback loop**:
- **Playground**: `composer analyse` — verifying type correctness of container registrations
- **Experiment**: Boot the application with `php artisan tinker` and resolve `app('workos')` — verify it returns our `WorkOS` service with a live SDK client
- **Check command**: `composer analyse`

### 3. WorkOS.php — Service Class Overhaul

**Pattern to follow**: Current `src/WorkOS.php`

**Overview**: Complete rewrite of the service class. The v5 SDK client is injected via constructor. All service accessors delegate to the client. The `SERVICE_MAP` constant and `__call()` magic are removed. New v5 services (connect, actions, pkce, etc.) get explicit typed accessors.

**Implementation steps**:

1. Update constructor to accept `\WorkOS\WorkOS` as the SDK client:
   ```php
   public function __construct(
       private readonly \WorkOS\WorkOS $client,
       private readonly SessionManager $session,
   ) {}
   ```

2. Remove `SERVICE_MAP` constant and `__call()` method entirely

3. Replace all service accessor methods. Each method delegates to the SDK client:
   ```php
   public function userManagement(): \WorkOS\UserManagement { return $this->client->userManagement(); }
   public function organizations(): \WorkOS\Organizations { return $this->client->organizations(); }
   public function sso(): \WorkOS\SSO { return $this->client->sso(); }
   public function directorySync(): \WorkOS\DirectorySync { return $this->client->directorySync(); }
   public function auditLogs(): \WorkOS\AuditLogs { return $this->client->auditLogs(); }
   public function webhookVerification(): mixed { return $this->client->webhookVerification(); }
   public function webhooks(): mixed { return $this->client->webhooks(); }
   public function sessionManager(): \WorkOS\SessionManager { return $this->client->sessionManager(); }
   public function featureFlags(): mixed { return $this->client->featureFlags(); }
   public function apiKeys(): mixed { return $this->client->apiKeys(); }
   public function connect(): mixed { return $this->client->connect(); }
   public function events(): mixed { return $this->client->events(); }
   public function organizationDomains(): mixed { return $this->client->organizationDomains(); }
   public function pipes(): mixed { return $this->client->pipes(); }
   public function radar(): mixed { return $this->client->radar(); }
   public function vault(): mixed { return $this->client->vault(); }
   public function actions(): mixed { return $this->client->actions(); }
   public function pkce(): mixed { return $this->client->pkce(); }
   public function multiFactorAuth(): mixed { return $this->client->multiFactorAuth(); }
   public function adminPortal(): mixed { return $this->client->adminPortal(); }
   public function authorization(): mixed { return $this->client->authorization(); }
   public function passwordless(): \WorkOS\Passwordless { return $this->client->passwordless(); }
   public function widgets(): mixed { return $this->client->widgets(); }
   ```
   Note: Use `mixed` for return types where we haven't verified the v5 class name yet — PHPStan will catch these and we'll fix to exact types.

4. Update `loginUrl()` — v5's `getAuthorizationUrl()` now makes an HTTP request and returns `SSOAuthorizeUrlResponse`. The `state` parameter is now a plain string, not an array. Update:
   ```php
   public function loginUrl(...): string {
       // $state must be json_encode'd if it's an array
       $stateStr = $state !== null ? json_encode($state) : null;
       $response = $this->userManagement()->getAuthorizationUrl(
           redirectUri: config('workos.redirect_uri'),
           state: $stateStr,
           provider: 'authkit',
           organizationId: $organizationId,
           loginHint: $loginHint,
           screenHint: $screenHint,
       );
       // v5 returns an object — extract the URL
       return $response->url; // or however the response exposes it
   }
   ```

5. Replace `validateApiKey()` manual Guzzle call with `$this->client->apiKeys()`:
   ```php
   public function validateApiKey(string $key): ?ApiKeyValidation {
       try {
           $result = $this->client->apiKeys()->validateApiKey(value: $key);
           return ApiKeyValidation::fromResponse((array) $result);
       } catch (\Exception) {
           return null;
       }
   }
   ```

6. Remove `GuzzleHttp\Client` and `GuzzleHttp\Exception\RequestException` imports
7. Remove all v4 SDK class imports (`WorkOS\UserManagement`, `WorkOS\Organizations`, etc.) — they're now accessed via `$this->client->`

8. Keep sub-service accessors that add value (feature-flag gating):
   - `vault()`, `radar()`, `pipes()`, `domains()` — keep the RuntimeException guard but delegate to `$this->client->vault()` etc. instead of instantiating our custom service classes (Phase 4 will complete this transition)
   - `flags()` and `fga()` — keep for now, Phase 4 will update them

9. Keep all static testing methods (`fake()`, `actingAs()`, `isFaked()`, `restore()`) unchanged
10. Keep all session convenience methods (`session()`, `validSession()`, `isAuthenticated()`, etc.) unchanged

**Key decisions**:
- Feature-gated sub-service methods (vault, radar, pipes, domains) keep their RuntimeException guards in Phase 1. Phase 4 changes what they delegate to.
- We use exact v5 return types where known, `mixed` where uncertain — PHPStan forces us to resolve all `mixed` types before the phase is complete.
- The `$instances` cache is removed — the v5 SDK client handles its own service instance caching internally.

**Feedback loop**:
- **Playground**: `composer analyse`
- **Experiment**: Check that all 22+ accessor methods return the correct type from the v5 SDK client
- **Check command**: `composer analyse`

### 4. Facade Docblock Update

**Pattern to follow**: Current `src/Facades/WorkOS.php`

**Overview**: Update all `@method` annotations to reflect v5 types and new service accessors.

**Implementation steps**:

1. Remove `@method` lines referencing v4 classes (`\WorkOS\MFA`, `\WorkOS\Portal`, `\WorkOS\Webhook`)
2. Add new `@method` lines for every v5 service accessor
3. Update return types to match v5 SDK class names
4. Add `@method` lines for new convenience methods if any were added

### 5. WorkOSFake Baseline Update

**Pattern to follow**: `src/Testing/WorkOSFake.php`

**Overview**: Update WorkOSFake to match the new WorkOS.php method signatures so tests can compile. Full behavioral updates happen in Phase 5.

**Implementation steps**:

1. Add stub methods for every new service accessor (return null or throw — they're test stubs)
2. Remove any methods that reference v4 classes no longer in use
3. Update `actingAs()` and related methods if their signatures changed
4. Ensure `InteractsWithWorkOS` trait still swaps correctly

## Testing Requirements

### Validation in This Phase

This phase focuses on compile-time correctness, not behavioral correctness. The primary validation is:

1. `composer update` succeeds with v5 installed
2. `composer analyse` (PHPStan level 8) passes — all type references resolve to real v5 classes
3. `php artisan tinker` → `app('workos')` resolves without exceptions

Behavioral tests are adapted in Phases 2-4 alongside the code they test.

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| v5 class not found (bad namespace) | PHPStan catches at analysis time — fix the import |
| SDK client constructor fails (bad config) | Same lazy-boot validation as v4 — throw `WorkOSException::missingConfiguration()` |
| v5 method returns unexpected type | PHPStan catches return type mismatch — align accessor return type |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Service Provider | SDK client fails to instantiate | Missing/empty API key or client ID | App won't boot | Keep existing validation logic, throw clear exception |
| WorkOS.php | Accessor returns wrong type | v5 SDK returns different class than expected | PHPStan error / runtime crash | Use `mixed` initially, resolve via PHPStan |
| loginUrl() | v5 getAuthorizationUrl() makes HTTP call | Network unavailable in local dev | Login redirect fails | This is existing SDK behavior — no change from v4 |
| WorkOSFake | Missing method stub | New accessor added to WorkOS but not to Fake | Test compilation fails | Mirror every WorkOS accessor in the Fake |

## Validation Commands

```bash
# Install v5 SDK
composer update workos/workos-php --with-all-dependencies

# Static analysis — primary validation
composer analyse

# Quick boot check
cd workbench && php artisan tinker --execute="app('workos')"

# Formatting
composer format
```

## Open Items

- [ ] Verify exact v5 return types for each service accessor (some may be autogenerated classes we haven't seen yet — PHPStan will surface these)
- [ ] Check if v5's `\WorkOS\WorkOS` constructor accepts a `baseUrl` parameter for test environments
- [ ] Confirm whether `\WorkOS\WorkOS::getApiKey()` still works in v5 (used in `FGAService` — Phase 4 dependency)

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
