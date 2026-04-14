# Implementation Spec: Platform Parity - Phase 2 (Auth Flow Enhancements)

**Contract**: ./contract.md
**Estimated Effort**: S

## Technical Approach

Add `screenHint` and `loginHint` parameters to `loginUrl()` and thread them through from `AuthController`. The WorkOS PHP SDK's `getAuthorizationUrl()` already supports both — confirmed from `vendor/workos/workos-php/lib/UserManagement.php:686-695`:

```php
public function getAuthorizationUrl(
    $redirectUri,
    $state = null,
    $provider = null,
    ?string $connectionId = null,
    ?string $organizationId = null,
    ?string $domainHint = null,
    ?string $loginHint = null,   // exact param name
    ?string $screenHint = null,  // exact param name
    ?array $providerScopes = null
)
```

The SDK accepts `screenHint` values `'sign-up'` and `'sign-in'` (documented in the `@param` docblock as "The page that the user will be redirected to when the provider is authkit"). `loginHint` is "Username/email hint that will be passed as a parameter to the IdP login page."

`WorkOS::signUpUrl()` is a thin convenience method — it calls `loginUrl()` with `screenHint: 'sign-up'` and passes through `organizationId` and `state` for completeness.

`AuthController::login()` already reads query params and forwards them to `WorkOS::loginUrl()`. We extend this to also read `screen_hint` and `login_hint` from the request (snake_case to match the OAuth convention used by the existing `organization_id` param).

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/WorkOSServiceTest.php tests/Feature/AuthFlowTest.php`

**Playground**: Test suite — no external calls required, `loginUrl()` builds the URL locally using the SDK.

**Why this approach**: Changes are confined to two files (`WorkOS.php`, `AuthController.php`) plus the facade docblock. The SDK already does the heavy lifting.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOS.php` | Add `screenHint` and `loginHint` params to `loginUrl()`; add `signUpUrl()` convenience method |
| `src/Facades/WorkOS.php` | Update `@method` for `loginUrl()`; add `@method` for `signUpUrl()` |
| `src/Http/Controllers/AuthController.php` | Read `screen_hint` and `login_hint` query params; pass to `WorkOS::loginUrl()` |
| `tests/Unit/WorkOSServiceTest.php` | Add tests for new `loginUrl()` params and `signUpUrl()` |
| `tests/Feature/AuthFlowTest.php` | Add tests for `screen_hint` and `login_hint` pass-through from the HTTP request |

## Implementation Details

### WorkOS::loginUrl()

**Pattern to follow**: `src/WorkOS.php:121-133` — existing `loginUrl()` implementation.

```php
/**
 * @param  array<string, mixed>|null  $state
 */
public function loginUrl(
    ?string $organizationId = null,
    ?array $state = null,
    ?string $screenHint = null,
    ?string $loginHint = null,
): string {
    /** @var UserManagement $userManagement */
    $userManagement = $this->userManagement();

    return $userManagement->getAuthorizationUrl(
        redirectUri: config('workos.redirect_uri'),
        state: $state,
        provider: 'authkit',
        organizationId: $organizationId,
        loginHint: $loginHint,
        screenHint: $screenHint,
    );
}
```

**Key decisions**:
- `screenHint` and `loginHint` are both optional nullables — callers that don't pass them get identical behavior to today.
- Parameter order puts `screenHint` before `loginHint` to match the authkit-nextjs API surface.
- Named arguments used when calling `getAuthorizationUrl()` to avoid positional confusion with the skipped `connectionId` and `domainHint` params.

### WorkOS::signUpUrl()

```php
/**
 * @param  array<string, mixed>|null  $state
 */
public function signUpUrl(?string $organizationId = null, ?array $state = null): string
{
    return $this->loginUrl(
        organizationId: $organizationId,
        state: $state,
        screenHint: 'sign-up',
    );
}
```

### Facade Docblock Update

```php
// src/Facades/WorkOS.php — update the loginUrl @method and add signUpUrl
 * @method static string loginUrl(?string $organizationId = null, ?array<string, mixed> $state = null, ?string $screenHint = null, ?string $loginHint = null)
 * @method static string signUpUrl(?string $organizationId = null, ?array<string, mixed> $state = null)
```

### AuthController::login()

**Pattern to follow**: `src/Http/Controllers/AuthController.php:19-35` — existing `login()` method.

```php
public function login(Request $request): RedirectResponse
{
    $organizationId = $request->query('organization_id');
    $screenHint = $request->query('screen_hint');
    $loginHint = $request->query('login_hint');
    $state = $request->query('state');

    $stateArray = null;
    if ($state) {
        $stateArray = ['return_to' => $state];
    } elseif ($request->query('return_to')) {
        $stateArray = ['return_to' => $request->query('return_to')];
    }

    return redirect(WorkOS::loginUrl(
        organizationId: is_string($organizationId) ? $organizationId : null,
        state: $stateArray,
        screenHint: is_string($screenHint) ? $screenHint : null,
        loginHint: is_string($loginHint) ? $loginHint : null,
    ));
}
```

**Key decisions**:
- Query param names use snake_case (`screen_hint`, `login_hint`) to be consistent with the existing `organization_id` convention in the controller.
- `is_string()` guard matches the existing pattern for `$organizationId` — prevents passing non-string query values to the SDK.
- No validation of `screenHint` values at the controller layer — the SDK throws `UnexpectedValueException` if an invalid value is passed, which will surface as a 500. If tighter control is needed, that's a future concern.

## Testing Requirements

### Unit Tests (`tests/Unit/WorkOSServiceTest.php`)

**Key test cases**:
- `loginUrl()` with no params still builds a valid authorization URL (regression)
- `loginUrl(screenHint: 'sign-in')` passes `screen_hint` to the SDK URL
- `loginUrl(screenHint: 'sign-up')` passes `screen_hint` to the SDK URL
- `loginUrl(loginHint: 'user@example.com')` passes `login_hint` to the SDK URL
- `loginUrl()` with all params combines them correctly in the URL
- `signUpUrl()` produces a URL with `screen_hint=sign-up`
- `signUpUrl(organizationId: 'org_123')` includes both `organization_id` and `screen_hint=sign-up`

Example unit test pattern (following `tests/Unit/WorkOSServiceTest.php` style):

```php
it('passes screen hint to login url', function () {
    \WorkOS\WorkOS::setApiKey('sk_test_key');
    \WorkOS\WorkOS::setClientId('client_id_123');

    $sessionManager = Mockery::mock(SessionManager::class);
    $workos = new \WorkOS\AuthKit\WorkOS($sessionManager);

    $url = $workos->loginUrl(screenHint: 'sign-up');

    expect($url)->toContain('screen_hint=sign-up');
});

it('signUpUrl sets screen hint to sign-up', function () {
    \WorkOS\WorkOS::setApiKey('sk_test_key');
    \WorkOS\WorkOS::setClientId('client_id_123');

    $sessionManager = Mockery::mock(SessionManager::class);
    $workos = new \WorkOS\AuthKit\WorkOS($sessionManager);

    $url = $workos->signUpUrl(organizationId: 'org_123');

    expect($url)
        ->toContain('screen_hint=sign-up')
        ->toContain('organization_id=org_123');
});
```

### Feature Tests (`tests/Feature/AuthFlowTest.php`)

**Key test cases**:
- `GET /auth/login?screen_hint=sign-up` redirects with `screen_hint=sign-up` in the Location header
- `GET /auth/login?screen_hint=sign-in` redirects with `screen_hint=sign-in` in the Location header
- `GET /auth/login?login_hint=user%40example.com` redirects with `login_hint` in the Location header
- `GET /auth/login?screen_hint=sign-up&login_hint=user%40example.com` passes both params
- `GET /auth/login` (no new params) still redirects correctly (regression)

Example feature test pattern (following `tests/Feature/AuthFlowTest.php` style):

```php
it('passes screen hint to workos authorization url', function () {
    $response = $this->get('/auth/login?screen_hint=sign-up');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('screen_hint=sign-up');
});

it('passes login hint to workos authorization url', function () {
    $response = $this->get('/auth/login?login_hint=user%40example.com');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('login_hint=user%40example.com');
});
```

## Validation Commands

```bash
composer analyse
vendor/bin/pest tests/Unit/WorkOSServiceTest.php tests/Feature/AuthFlowTest.php
composer test
```
