# Implementation Spec: WorkOS SDK v5 Migration - Phase 2

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

Phase 2 migrates the authentication and session management layer — the most critical part of the package. The v4 pattern uses `UserManagement::loadSealedSession()` to get a `CookieSession` object, then calls `CookieSession::authenticate()` and `CookieSession::refresh()`, which return typed response objects (`SessionAuthenticationSuccessResponse`).

v5 moves all this to `$workos->sessionManager()`, which returns plain associative arrays (`['authenticated' => true, ...]`) instead of typed objects. Our `WorkOSSession` value object must adapt its factory methods to construct from these arrays while preserving the same public API for downstream code.

The `AuthController` also needs updates for v5 method renames (`authenticateWithCode` signature changes) and the `HaliteSessionEncryption` class move.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=Session`

**Playground**: Pest test suite — session management is data-layer logic, best validated via unit tests.

**Why this approach**: Session management is pure logic with no UI. Tests give immediate feedback on whether session construction, expiry, refresh, and storage work correctly.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/Auth/SessionManager.php` | Replace v4 `CookieSession`/`loadSealedSession()` with v5 `$workos->sessionManager()->authenticate()`. Replace `HaliteSessionEncryption::seal()` with v5 equivalent. Update `attemptRefresh()` for v5 refresh flow. Update `getLogoutUrl()` for v5 pattern. |
| `src/Auth/WorkOSSession.php` | Update `fromAuthResponse()` to handle v5's plain array structure. Remove dependency on `SessionAuthenticationSuccessResponse`, `RoleResponse`, `FeatureFlag` resource classes. |
| `src/Auth/WorkOSGuard.php` | Minor updates if SessionManager interface changed |
| `src/Http/Controllers/AuthController.php` | Update `authenticateWithCode()` call for v5 signature. Update response handling for v5 return types. |
| `src/WorkOSServiceProvider.php` | Update `registerSessionManager()` — SessionManager now needs the v5 SDK client for `sessionManager()` access |
| `tests/Unit/WorkOSSessionTest.php` | Update for v5 array response shapes |
| `tests/Unit/SessionManagerTest.php` | Update mocks for v5 sessionManager instead of CookieSession |
| `tests/Feature/AuthFlowTest.php` | Update for v5 authenticateWithCode response |

## Implementation Details

### 1. SessionManager Migration

**Pattern to follow**: Current `src/Auth/SessionManager.php`

**Overview**: The v4 `SessionManager` uses a `CookieSession` object (obtained from `UserManagement::loadSealedSession()`) as an intermediary. v5 replaces this with `$workos->sessionManager()->authenticate()` which takes the sealed session data and cookie password directly and returns a plain array.

**Implementation steps**:

1. Add the v5 SDK client to the constructor:
   ```php
   public function __construct(
       private readonly \WorkOS\WorkOS $client,
       private readonly string $cookiePassword,
       private readonly string $cookieName = 'wos-session',
   ) {}
   ```

2. Replace `getCookieSession()` + `authenticate()` flow in `getSession()`:
   ```php
   public function getSession(): ?WorkOSSession
   {
       if ($this->cachedSession !== null) {
           return $this->cachedSession;
       }

       $sealedSession = request()->cookie($this->cookieName);
       if (! $sealedSession || ! is_string($sealedSession)) {
           return null;
       }

       try {
           $result = $this->client->sessionManager()->authenticate(
               sessionData: $sealedSession,
               cookiePassword: $this->cookiePassword,
           );

           if (! ($result['authenticated'] ?? false)) {
               return null;
           }

           $this->cachedSession = WorkOSSession::fromSessionManagerResponse($result);
           return $this->cachedSession;
       } catch (\Exception) {
           return null;
       }
   }
   ```

3. Replace `attemptRefresh()`:
   ```php
   private function attemptRefresh(): ?WorkOSSession
   {
       $sealedSession = request()->cookie($this->cookieName);
       if (! $sealedSession || ! is_string($sealedSession)) {
           return null;
       }

       try {
           $result = $this->client->sessionManager()->refresh(
               sessionData: $sealedSession,
               cookiePassword: $this->cookiePassword,
           );

           if (! ($result['authenticated'] ?? false)) {
               $this->cachedSession = null;
               return null;
           }

           // Store the new sealed session if tokens were refreshed
           if (isset($result['sealed_session'])) {
               $this->storeSealedCookie($result['sealed_session']);
           }

           $this->cachedSession = WorkOSSession::fromSessionManagerResponse($result);
           return $this->cachedSession;
       } catch (\Exception) {
           $this->cachedSession = null;
           return null;
       }
   }
   ```

4. Replace `store()` — use v5's `HaliteSessionEncryption` (now at `\WorkOS\Session\HaliteSessionEncryption` or equivalent in v5):
   ```php
   public function store(array $authResponse): WorkOSSession
   {
       $this->cachedSession = null;

       $accessToken = $authResponse['access_token'] ?? null;
       $refreshToken = $authResponse['refresh_token'] ?? null;

       if ($accessToken && $refreshToken) {
           // Use v5 SDK's seal method
           $sealedSession = $this->client->sessionManager()->seal(
               sessionData: ['access_token' => $accessToken, 'refresh_token' => $refreshToken],
               cookiePassword: $this->cookiePassword,
           );

           $this->storeSealedCookie($sealedSession);
       }

       return WorkOSSession::fromAuthResponse($authResponse);
   }
   ```

5. Replace `getLogoutUrl()`:
   ```php
   public function getLogoutUrl(?string $returnTo = null): ?string
   {
       $sealedSession = request()->cookie($this->cookieName);
       if (! $sealedSession || ! is_string($sealedSession)) {
           return null;
       }

       try {
           return $this->client->sessionManager()->getLogoutUrl(
               sessionData: $sealedSession,
               cookiePassword: $this->cookiePassword,
               returnTo: $returnTo,
           );
       } catch (\Exception) {
           return null;
       }
   }
   ```

6. Extract `storeSealedCookie()` private helper (DRY for store + refresh):
   ```php
   private function storeSealedCookie(string $sealedSession): void
   {
       Cookie::queue(
           $this->cookieName,
           $sealedSession,
           60 * 24 * 30,
           '/',
           config('session.domain'),
           config('session.secure', false),
           true,
       );
   }
   ```

7. Remove `$cookieSession` property, `getCookieSession()` method, and all v4 SDK imports (`CookieSession`, `HaliteSessionEncryption`, `SessionAuthenticationSuccessResponse`, `RoleResponse`, `Impersonator`, `FeatureFlag`, `BaseRequestException`, `UnexpectedValueException`)

8. Remove `buildWorkOSSession()` and `impersonatorToArray()` — replaced by `WorkOSSession::fromSessionManagerResponse()`

**Key decisions**:
- SessionManager now takes the v5 `\WorkOS\WorkOS` SDK client as a constructor dependency, not just the cookie password. This is necessary because v5 session operations live on `$client->sessionManager()`.
- The `CookieSession` intermediary is eliminated — we go directly from sealed cookie → v5 `sessionManager()->authenticate()`.

**Feedback loop**:
- **Playground**: Pest tests for SessionManager
- **Experiment**: Test with valid sealed session, expired session, corrupted cookie, missing cookie, and refresh scenario
- **Check command**: `composer test -- --filter=SessionManager`

### 2. WorkOSSession Value Object Adaptation

**Pattern to follow**: Current `src/Auth/WorkOSSession.php`

**Overview**: v5's `sessionManager()->authenticate()` returns a plain associative array instead of `SessionAuthenticationSuccessResponse`. Add a new factory method `fromSessionManagerResponse()` that handles this format. Keep `fromAuthResponse()` for the OAuth callback flow and `fromArray()` for serialization.

**Implementation steps**:

1. Add `fromSessionManagerResponse()` factory:
   ```php
   public static function fromSessionManagerResponse(array $response): self
   {
       // v5 returns: authenticated, session_id, organization_id, 
       // roles, permissions, feature_flags, entitlements,
       // user (object with id), access_token, refresh_token, impersonator
       return new self(
           userId: (string) ($response['user']['id'] ?? $response['user_id'] ?? ''),
           accessToken: (string) ($response['access_token'] ?? ''),
           refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
           expiresAt: isset($response['expires_at'])
               ? Carbon::parse($response['expires_at'])
               : Carbon::now()->addMinutes((int) config('workos.session.access_token_lifetime', 60)),
           sessionId: isset($response['session_id']) ? (string) $response['session_id'] : null,
           roles: static::extractRoles($response),
           permissions: $response['permissions'] ?? [],
           featureFlags: static::extractFeatureFlags($response),
           entitlements: $response['entitlements'] ?? [],
           organizationId: isset($response['organization_id']) ? (string) $response['organization_id'] : null,
           impersonator: isset($response['impersonator']) && is_array($response['impersonator']) ? $response['impersonator'] : null,
       );
   }
   ```

2. Add private helpers for role/flag extraction (v5 may return objects or strings):
   ```php
   private static function extractRoles(array $response): array
   {
       $roles = $response['roles'] ?? [];
       return array_map(fn ($role) => is_string($role) ? $role : ($role['slug'] ?? (string) $role), $roles);
   }

   private static function extractFeatureFlags(array $response): array
   {
       $flags = $response['feature_flags'] ?? [];
       return array_map(fn ($flag) => is_string($flag) ? $flag : ($flag['slug'] ?? (string) $flag), $flags);
   }
   ```

3. Update `fromAuthResponse()` — remove any references to `SessionAuthenticationSuccessResponse` or `RoleResponse` types. This method handles the OAuth callback response which in v5 is also a plain array.

4. Keep `fromArray()` and `toArray()` unchanged — they work with our internal format.

**Key decisions**:
- We add a new factory method rather than modifying `fromAuthResponse()` because the session manager response shape may differ from the OAuth callback response shape.
- Role/flag extraction is defensive — handles both string arrays (v5 simplified) and object arrays (v5 typed resources that might appear).

### 3. AuthController Updates

**Pattern to follow**: `src/Http/Controllers/AuthController.php`

**Overview**: Update `authenticateWithCode()` call for v5 signature changes. v5 no longer accepts leading credential arguments — credentials come from the `WorkOS` client instance.

**Implementation steps**:

1. Update the `authenticateWithCode` call:
   ```php
   // v4: $this->userManagement->authenticateWithCode($code)
   // v5: $workos->userManagement()->authenticateWithCode(code: $code)
   ```
2. Handle the v5 response shape — it's still an auth response array, but field names or nesting may differ
3. Update any imports referencing v4 SDK classes

**Feedback loop**:
- **Playground**: Feature test for auth flow
- **Experiment**: Test login redirect → callback → session creation
- **Check command**: `composer test -- --filter=AuthFlow`

### 4. ServiceProvider SessionManager Registration Update

**Overview**: SessionManager now needs the v5 SDK client.

**Implementation steps**:

1. Update `registerSessionManager()`:
   ```php
   protected function registerSessionManager(): void
   {
       $this->app->singleton(SessionManager::class, function ($app) {
           $appKey = config('app.key');
           if (str_starts_with($appKey, 'base64:')) {
               $appKey = base64_decode(substr($appKey, 7));
           }

           return new SessionManager(
               $app->make(\WorkOS\WorkOS::class),
               $appKey,
               config('workos.session.cookie_name', 'wos-session'),
           );
       });
   }
   ```

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| v5 authenticate() returns `['authenticated' => false]` | Return null from getSession(), trigger redirect to login |
| v5 refresh() fails | Return null, clear cached session — same as v4 |
| Corrupted cookie (bad sealed data) | v5 sessionManager throws exception → caught, return null |
| Missing cookie | Early return null — unchanged from v4 |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| SessionManager | v5 authenticate() response shape differs from expected | SDK response doesn't match our array parsing | Session construction fails, users can't authenticate | Defensive array access with defaults |
| SessionManager | Sealed session format incompatible between v4 and v5 | Existing v4 cookies in browsers | All existing sessions invalidated | This is expected — users re-login. Document in upgrade guide. |
| WorkOSSession | Role/flag extraction breaks | v5 changes role format from objects to strings or vice versa | Roles/permissions not populated | Defensive extraction helpers handle both formats |
| AuthController | authenticateWithCode response changed | v5 returns different structure | Session creation fails after OAuth callback | Test the full OAuth flow end-to-end |

## Validation Commands

```bash
# Session-specific tests
composer test -- --filter=Session

# Auth flow tests
composer test -- --filter=Auth

# Static analysis
composer analyse

# Full test suite (may have failures from Phases 3-4 code)
composer test
```

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
