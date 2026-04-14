# Implementation Spec: WorkOS SDK v5 Migration - Phase 4

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

Phase 4 is the payoff — replacing all manual HTTP implementations with native v5 SDK service calls. This eliminates:
- `WorkOS\Client::request()` internal SDK usage (FGAService)
- `Illuminate\Support\Facades\Http` calls to WorkOS endpoints (VaultService, RadarService, PipesService, DomainService)
- Raw `GuzzleHttp\Client` calls to WorkOS endpoints (validateApiKey — already replaced in Phase 1)

Each service wrapper is rewritten to delegate to its corresponding v5 SDK service. The wrappers still provide value by:
- Adding feature-flag gating (RuntimeException if feature not enabled)
- Adding request-scoped caching (FGAService)
- Providing a consistent Laravel-idiomatic interface
- Converting v5 typed responses to arrays where our API expects them

The v5 SDK provides: `authorization()`, `vault()`, `radar()`, `pipes()`, `organizationDomains()`, `featureFlags()`, `apiKeys()`.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=FGA --filter=Vault --filter=Radar --filter=Pipes --filter=Domain --filter=FeatureFlag`

**Playground**: Pest test suite — all services have existing tests using `Http::fake()` or Mockery. Those tests need to be updated to mock the v5 SDK services instead.

**Why this approach**: These are data-layer services with no UI. Test suites validate the SDK delegation is correct.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/FGA/FGAService.php` | Replace all `Client::request()` calls with `$workos->authorization()` method calls |
| `src/Services/VaultService.php` | Replace all `Http::` facade calls with `$workos->vault()` method calls |
| `src/Services/RadarService.php` | Replace all `Http::` facade calls with `$workos->radar()` method calls |
| `src/Services/PipesService.php` | Replace all `Http::` facade calls with `$workos->pipes()` method calls |
| `src/Services/DomainService.php` | Replace all `Http::` facade calls with `$workos->organizationDomains()` method calls |
| `src/FeatureFlags/FeatureFlagService.php` | Replace `Organizations::listOrganizationFeatureFlags()` with `$workos->featureFlags()` |
| `src/WorkOS.php` | Update `vault()`, `radar()`, `pipes()`, `domains()`, `fga()`, `flags()` sub-service accessors to pass the v5 client |
| `src/WorkOSServiceProvider.php` | Update singleton registrations for services that need the v5 client |
| Tests for all above | Replace `Http::fake()` / `Mockery::mock(Client::class)` with v5 SDK mocks |

## Implementation Details

### 1. FGAService — Replace Client::request() with authorization()

**Pattern to follow**: `src/FGA/FGAService.php`

**Overview**: FGAService currently uses `WorkOS\Client::request()` — the SDK's internal HTTP client — for all FGA operations. v5 provides `$workos->authorization()` with proper typed methods. This is the most invasive change because we're replacing 4 different `Client::request()` calls.

**Implementation steps**:

1. Add v5 SDK client to constructor:
   ```php
   public function __construct(
       private readonly \WorkOS\WorkOS $client,
       private readonly SessionManager $session,
   ) {}
   ```

2. Replace `performCheck()`:
   ```php
   private function performCheck(string $userId, string $permission, string $resourceType, string $resourceId): FGAAccessResult
   {
       try {
           $result = $this->client->authorization()->checkAccess(
               userId: $userId,
               permission: $permission,
               resourceType: $resourceType,
               resourceId: $resourceId,
           );
           $allowed = $result->allowed ?? false;
       } catch (\Exception) {
           $allowed = false;
       }

       return new FGAAccessResult(
           allowed: $allowed,
           userId: $userId,
           permission: $permission,
           resource: new FGAResource($resourceType, $resourceId),
       );
   }
   ```

3. Replace `listResources()`:
   ```php
   public function listResources(string $userId, string $permission, string $resourceType): array
   {
       try {
           $response = $this->client->authorization()->listAccessibleResources(
               userId: $userId,
               permission: $permission,
               resourceType: $resourceType,
           );
           return array_map(
               fn ($r) => new FGAResource($r->resourceType, $r->resourceId),
               $response->data ?? [],
           );
       } catch (\Exception) {
           return [];
       }
   }
   ```

4. Replace `assign()`:
   ```php
   public function assign(string $userId, string $roleSlug, string $resourceType, string $resourceId): bool
   {
       try {
           $this->client->authorization()->createRoleAssignment(
               userId: $userId,
               roleSlug: $roleSlug,
               resourceType: $resourceType,
               resourceId: $resourceId,
           );
           $this->flushCache($userId);
           return true;
       } catch (\Exception) {
           return false;
       }
   }
   ```

5. Replace `unassign()` similarly with `$this->client->authorization()->deleteRoleAssignment()`

6. Remove `use WorkOS\Client;` import

**Key decisions**:
- Method names on `authorization()` will need verification against the actual v5 SDK — the names above are best guesses based on the changelog. PHPStan will catch mismatches.
- The caching layer in FGAService is preserved — it still provides value for request-scoped deduplication.
- Error handling stays the same (catch-all returns false/empty).

**Feedback loop**:
- **Playground**: FGA test suite
- **Experiment**: Test check (allowed/denied), listResources (empty/populated), assign (success/failure), unassign
- **Check command**: `composer test -- --filter=FGA`

### 2. VaultService — Replace Http:: with vault()

**Pattern to follow**: `src/Services/VaultService.php`

**Overview**: VaultService makes 8 different `Http::` calls. v5 provides `$workos->vault()` with methods like `readObject()`, `listObjects()`, `createObject()`, etc.

**Implementation steps**:

1. Add v5 SDK client to constructor:
   ```php
   public function __construct(
       private readonly \WorkOS\WorkOS $client,
   ) {}
   ```

2. Replace each method:
   ```php
   public function store(string $name, string $value, array $context = []): array
   {
       $result = $this->client->vault()->createObject(name: $name, value: $value, context: $context);
       return (array) $result; // or $result->toArray() if available
   }

   public function get(string $id): array
   {
       return (array) $this->client->vault()->readObject(id: $id);
   }

   // ... similar for getByName, update, delete, list, versions, encrypt, decrypt
   ```

3. Remove `use Illuminate\Support\Facades\Http;` and the private `request()` helper entirely

4. Remove manual `Authorization: Bearer` header construction — the v5 client handles auth

5. Remove `config('workos.widgets.base_url')` dependency — the v5 client has its own base URL

**Key decisions**:
- Return type stays as `array<string, mixed>` for now. v5 returns typed objects, but our public API returns arrays. We convert with `(array)` or `->toArray()`. This could be improved later to return typed objects.
- Error handling: v5 throws typed exceptions (`NotFoundException`, `BadRequestException`, etc.) — we let these propagate. The current `RuntimeException` on HTTP failure is equivalent.

**Feedback loop**:
- **Playground**: Vault test suite
- **Experiment**: Test store/get/update/delete/list/encrypt/decrypt
- **Check command**: `composer test -- --filter=Vault`

### 3. RadarService — Replace Http:: with radar()

**Pattern to follow**: `src/Services/RadarService.php`

**Overview**: RadarService makes 4 `Http::` calls. v5 provides `$workos->radar()`.

**Implementation steps**:

1. Add v5 SDK client, replace all 4 methods:
   - `createAttempt()` → `$this->client->radar()->createAttempt(...)`
   - `updateAttempt()` → `$this->client->radar()->updateAttempt(...)`
   - `addToList()` → `$this->client->radar()->addToList(...)`
   - `removeFromList()` → `$this->client->radar()->removeFromList(...)`

2. Remove `Http::` import and private `request()` helper

### 4. PipesService — Replace Http:: with pipes()

**Pattern to follow**: `src/Services/PipesService.php`

**Overview**: PipesService makes 5 `Http::` calls across different endpoints. v5 provides `$workos->pipes()` and possibly `$workos->connect()`.

**Implementation steps**:

1. Add v5 SDK client, replace methods:
   - `listProviders()` → `$this->client->pipes()->listProviders()`
   - `getAuthorizationUrl()` → `$this->client->pipes()->authorize(...)` or `$this->client->connect()->...`
   - `getConnectedAccount()` → `$this->client->pipes()->getConnectedAccount(...)`
   - `deleteConnectedAccount()` → `$this->client->pipes()->deleteConnectedAccount(...)`
   - `getAccessToken()` → `$this->client->pipes()->getAccessToken(...)`

2. Remove `Http::` import and private `request()` helper

3. Note: Some of these may live on `userManagement()` in v5 (e.g., connected_accounts endpoints). Check v5 SDK source.

### 5. DomainService — Replace Http:: with organizationDomains()

**Pattern to follow**: `src/Services/DomainService.php`

**Overview**: DomainService makes 4 `Http::` calls. v5 provides `$workos->organizationDomains()`.

**Implementation steps**:

1. Add v5 SDK client, replace methods:
   - `create()` → `$this->client->organizationDomains()->createOrganizationDomain(...)`
   - `get()` → `$this->client->organizationDomains()->getOrganizationDomain(...)`
   - `verify()` → `$this->client->organizationDomains()->verifyOrganizationDomain(...)`
   - `delete()` → `$this->client->organizationDomains()->deleteOrganizationDomain(...)`

2. Remove `Http::` import and private `request()` helper

### 6. FeatureFlagService — Replace with featureFlags()

**Pattern to follow**: `src/FeatureFlags/FeatureFlagService.php`

**Overview**: Currently uses `Organizations::listOrganizationFeatureFlags()`. v5 likely has `$workos->featureFlags()` as a standalone service.

**Implementation steps**:

1. Add v5 SDK client to constructor
2. Replace the org-based flag lookup with `$this->client->featureFlags()->...`
3. Session-based flag checking stays unchanged (reads from `WorkOSSession`)

### 7. WorkOS.php Sub-Service Accessor Updates

**Overview**: Update `vault()`, `radar()`, `pipes()`, `domains()`, `fga()`, `flags()` to pass the v5 client to the service constructors.

**Implementation steps**:

1. Update each accessor — services now receive the v5 SDK client:
   ```php
   public function vault(): VaultService
   {
       if (! config('workos.features.vault', false)) {
           throw new \RuntimeException('...');
       }
       return $this->instances['vault'] ??= new VaultService($this->client);
   }
   ```

2. Same pattern for radar, pipes, domains, fga, flags

3. Update ServiceProvider singleton registrations to inject the v5 client

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| v5 SDK method not found | PHPStan catches; fall back to raw client call if needed |
| v5 throws typed exception (NotFoundException, etc.) | Let propagate — replaces our RuntimeException pattern |
| v5 authorization service returns different shape | Adapt FGAAccessResult construction — defensive array/object access |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| FGAService | v5 authorization() method names wrong | Guessed names don't match actual v5 API | PHPStan errors, runtime failures | Verify against v5 SDK source before implementing |
| VaultService | v5 vault() returns typed objects, not arrays | Our API returns arrays | Type mismatch in downstream code | Convert with toArray() or cast |
| PipesService | Connected account endpoints on different v5 service | v5 splits pipes and connect | Method not found | Check v5 source for correct service |
| All services | v5 named parameter mismatch | v5 uses different param names than v4 | Wrong data sent to API | Always use named arguments, verify against v5 source |

## Validation Commands

```bash
# Targeted service tests
composer test -- --filter=FGA
composer test -- --filter=Vault
composer test -- --filter=Radar
composer test -- --filter=Pipes
composer test -- --filter=Domain
composer test -- --filter=FeatureFlag

# Verify no manual HTTP calls remain
grep -r "Http::withHeaders" src/ --include="*.php" | grep -v "test"
grep -r "Client::request" src/ --include="*.php"
grep -r "new GuzzleHttp" src/ --include="*.php"

# Static analysis
composer analyse

# Full test suite
composer test
```

## Rollout Considerations

After this phase, run the success criteria verification:

```bash
# Zero manual HTTP to WorkOS in src/
grep -rn "Client::request\|Http::withToken\|Http::withHeaders.*workos\|new GuzzleHttp\\\\Client" src/ --include="*.php"
# Should return empty
```

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
