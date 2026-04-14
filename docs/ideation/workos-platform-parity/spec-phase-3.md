# Implementation Spec: Platform Parity - Phase 3 (API Key Validation)

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

WorkOS provides a `POST /api_keys/validations` endpoint that takes an API key `value` in the request body and returns either the full API key object (valid) or `null` for the `api_key` field (invalid). This is used to authenticate customer-facing API requests where end users (or their servers) present a WorkOS-issued API key as a Bearer token.

The implementation has three layers:

1. **`WorkOS::validateApiKey(string $key)`** — wraps the HTTP call to `POST /api_keys/validations` using Guzzle (the same HTTP client the WorkOS PHP SDK already uses), returns a typed `ApiKeyValidation` value object or `null`.
2. **`workos.apikey` middleware** — extracts `Authorization: Bearer <key>` from the request, calls `validateApiKey()`, and on success injects the organization ID from the validated key into the request as `workos_organization_id` for downstream use. Returns 401 JSON on missing or invalid key.
3. **Laravel `auth:api` guard integration** — an `ApiKeyUserProvider` that uses `validateApiKey()` to satisfy Laravel's guard contract, so `auth('api')->user()` works on routes protected by the `workos.apikey` middleware.

The WorkOS PHP SDK does not currently expose a `validateApiKey()` method, so we call the WorkOS REST API directly via Guzzle. The SDK's `\WorkOS\WorkOS::getApiKey()` accessor provides the configured API key for authenticating the validation request itself.

**API details** (confirmed from `https://workos.com/docs/reference/authkit/api-keys`):

- **Endpoint**: `POST https://api.workos.com/api_keys/validations`
- **Auth**: `Authorization: Bearer {WORKOS_API_KEY}` (your secret key, not the customer's key)
- **Body**: `{"value": "<customer_api_key>"}`
- **Success**: Returns `{"api_key": {"object": "api_key", "id": "...", "owner": {"type": "...", "id": "..."}, "name": "...", "obfuscated_value": "...", "permissions": [...], "last_used_at": "...", "created_at": "...", "updated_at": "..."}}`
- **Invalid key**: Returns `{"api_key": null}`

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/ValidateApiKeyTest.php tests/Unit/ApiKeyMiddlewareTest.php`

**Playground**: Test suite with Mockery for HTTP client mocking — no live WorkOS calls needed in tests.

**Why this approach**: Keeps the WorkOS SDK as the source of truth for configuration (`getApiKey()`) while working around the SDK's current gap. The typed value object makes the middleware and guard implementation clean and testable without coupling to the raw response shape.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Auth/ApiKeyValidation.php` | Readonly value object representing a validated API key and its owner |
| `src/Http/Middleware/ValidateApiKey.php` | Middleware that validates Bearer token and injects org context |
| `src/Auth/ApiKeyUserProvider.php` | Laravel `UserProvider` implementation backed by `validateApiKey()` |
| `tests/Unit/ValidateApiKeyTest.php` | Unit tests for `WorkOS::validateApiKey()` |
| `tests/Unit/ApiKeyMiddlewareTest.php` | Unit tests for `ValidateApiKey` middleware |
| `tests/Unit/ApiKeyUserProviderTest.php` | Unit tests for `ApiKeyUserProvider` |

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOS.php` | Add `validateApiKey(string $key): ?ApiKeyValidation` method |
| `src/Facades/WorkOS.php` | Add `@method` docblock entry for `validateApiKey()` |
| `src/WorkOSServiceProvider.php` | Register `workos.apikey` middleware alias; register `ApiKeyUserProvider` with Laravel's auth system |
| `config/workos.php` | Add `api_keys` section with `base_url` override support |
| `tests/Feature/ApiKeyAuthTest.php` | Feature tests for the full middleware stack on a test route |

## Implementation Details

### ApiKeyValidation Value Object

**Pattern to follow**: `src/Auth/WorkOSSession.php` — readonly constructor with factory method.

```php
// src/Auth/ApiKeyValidation.php
namespace WorkOS\AuthKit\Auth;

readonly class ApiKeyValidation
{
    /**
     * @param  array<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $name,
        public string $obfuscatedValue,
        public array $permissions,
        public ?string $organizationId,
        public ?string $lastUsedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        /** @var array<string, mixed> $owner */
        $owner = $data['owner'] ?? [];

        $organizationId = ($owner['type'] ?? null) === 'organization'
            ? (string) $owner['id']
            : null;

        return new self(
            id: (string) $data['id'],
            ownerType: (string) ($owner['type'] ?? ''),
            ownerId: (string) ($owner['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            obfuscatedValue: (string) ($data['obfuscated_value'] ?? ''),
            permissions: isset($data['permissions']) && is_array($data['permissions'])
                ? $data['permissions']
                : [],
            organizationId: $organizationId,
            lastUsedAt: isset($data['last_used_at']) ? (string) $data['last_used_at'] : null,
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }
}
```

**Key decisions**:
- `organizationId` is derived from `owner.type === 'organization'` — this is the value injected into the request by the middleware for downstream consumption.
- `permissions` is `array<string>` consistent with how `WorkOSSession` handles permissions.
- No Carbon for timestamps — these are informational strings, not used for expiry checks.

### WorkOS::validateApiKey()

**Pattern to follow**: `src/WorkOS.php:121-133` — how `loginUrl()` delegates to the SDK.

```php
// Add to src/WorkOS.php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WorkOS\AuthKit\Auth\ApiKeyValidation;

public function validateApiKey(string $key): ?ApiKeyValidation
{
    try {
        $client = new Client;
        $baseUrl = config('workos.api_keys.base_url', 'https://api.workos.com');

        $response = $client->post("{$baseUrl}/api_keys/validations", [
            'headers' => [
                'Authorization' => 'Bearer '.\WorkOS\WorkOS::getApiKey(),
                'Content-Type' => 'application/json',
            ],
            'json' => ['value' => $key],
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        if (! isset($body['api_key']) || ! is_array($body['api_key'])) {
            return null;
        }

        return ApiKeyValidation::fromResponse($body['api_key']);
    } catch (RequestException) {
        return null;
    }
}
```

**Key decisions**:
- Returns `null` for both invalid keys (API returns `{"api_key": null}`) and HTTP errors — callers don't need to distinguish.
- `\WorkOS\WorkOS::getApiKey()` is the existing SDK static accessor for the configured API key, so we don't re-read config.
- `base_url` override in config supports staging/sandbox environments without code changes.
- Guzzle is already a transitive dependency via `workos/workos-php` — no new package dependency.

### ValidateApiKey Middleware

**Pattern to follow**: `src/Http/Middleware/EnsureWorkOSAuthenticated.php` — constructor injection, early return on failure, JSON response for API clients.

```php
// src/Http/Middleware/ValidateApiKey.php
namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Facades\WorkOS;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (! is_string($authHeader) || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $key = substr($authHeader, 7);
        $validation = WorkOS::validateApiKey($key);

        if ($validation === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $request->attributes->set('workos_api_key', $validation);

        if ($validation->organizationId !== null) {
            $request->attributes->set('workos_organization_id', $validation->organizationId);
        }

        return $next($request);
    }
}
```

**Key decisions**:
- Injects the full `ApiKeyValidation` object as `workos_api_key` on `$request->attributes` so downstream code can access permissions or key metadata without re-validating.
- Only injects `workos_organization_id` when the key owner is an organization — avoids setting the attribute to null which could mislead downstream code.
- Uses the Facade rather than constructor injection — `validateApiKey()` requires network access, and injecting the service class would complicate the middleware's test setup.
- No redirect — API routes always return JSON 401.

### ApiKeyUserProvider

This satisfies Laravel's `UserProvider` contract so `auth('api')->user()` can return a local `User` model on API-key-authenticated routes. Follows the pattern of resolving the local user model by `workos_id` once the key's owner is confirmed.

```php
// src/Auth/ApiKeyUserProvider.php
namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

class ApiKeyUserProvider implements UserProvider
{
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model', 'App\\Models\\User');

        if (! class_exists($userModel)) {
            return null;
        }

        if (method_exists($userModel, 'findByWorkOSId')) {
            /** @var Authenticatable|null */
            return $userModel::findByWorkOSId((string) $identifier);
        }

        /** @var Authenticatable|null */
        return $userModel::where('workos_id', $identifier)->first();
    }

    public function retrieveByToken(mixed $identifier, mixed $token): ?Authenticatable
    {
        $validation = app(\WorkOS\AuthKit\WorkOS::class)->validateApiKey((string) $token);

        if ($validation === null) {
            return null;
        }

        return $this->retrieveById($validation->ownerId);
    }

    public function updateRememberToken(Authenticatable $user, mixed $token): void {}

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
```

**Key decisions**:
- `retrieveByToken()` is the primary path — Laravel's `auth()->user()` with a bearer token guard calls this.
- `retrieveById()` looks up by `workos_id`, consistent with `HasWorkOSId` trait convention.
- `retrieveByCredentials()` and `validateCredentials()` return null/false — this provider only handles token-based auth.
- The guard registration in `WorkOSServiceProvider` should be configured as a `token` guard type pointing at `apikey` provider.

### WorkOSServiceProvider Registration

```php
// Add to configureMiddleware() in src/WorkOSServiceProvider.php
$router->aliasMiddleware('workos.apikey', ValidateApiKey::class);

// Add to configureGuard() in src/WorkOSServiceProvider.php — register the user provider
Auth::provider('workos-apikey', function ($app) {
    return new ApiKeyUserProvider;
});
```

### Config Addition

```php
// Add to config/workos.php
/*
|--------------------------------------------------------------------------
| API Keys Configuration
|--------------------------------------------------------------------------
|
| Configuration for WorkOS API key validation. The base_url can be
| overridden for staging or local development environments.
|
*/

'api_keys' => [
    'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
],
```

**Note**: `WORKOS_BASE_API_URL` is already used by `workos.widgets.base_url` — reusing the same env var keeps configuration minimal. If the application needs different base URLs for widgets vs. API key validation, the env var can be split.

## Testing Requirements

### Unit Tests (`tests/Unit/ValidateApiKeyTest.php`)

**Key test cases**:
- `validateApiKey()` with a valid key returns an `ApiKeyValidation` instance with correct fields
- `validateApiKey()` when the API returns `{"api_key": null}` returns `null`
- `validateApiKey()` when Guzzle throws `RequestException` returns `null`
- `ApiKeyValidation::fromResponse()` correctly extracts `organizationId` when `owner.type` is `'organization'`
- `ApiKeyValidation::fromResponse()` sets `organizationId` to `null` when `owner.type` is not `'organization'`
- `ApiKeyValidation::fromResponse()` populates `permissions` array from response

Example pattern (Mockery on Guzzle client):

```php
it('returns null when api key is invalid', function () {
    $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $mockResponse->shouldReceive('getBody')->andReturn(
        \GuzzleHttp\Psr7\Utils::streamFor('{"api_key": null}')
    );

    $mockClient = Mockery::mock(\GuzzleHttp\Client::class);
    $mockClient->shouldReceive('post')->andReturn($mockResponse);

    // inject via app binding or constructor in test...

    expect($result)->toBeNull();
});
```

### Unit Tests (`tests/Unit/ApiKeyMiddlewareTest.php`)

**Key test cases**:
- Missing `Authorization` header returns 401 JSON
- `Authorization: Basic ...` (non-Bearer) returns 401 JSON
- Valid Bearer key with successful `validateApiKey()` calls `$next` and continues
- Valid key with `organizationId` sets `workos_organization_id` on request attributes
- Valid key with no `organizationId` (user-owned key) does not set `workos_organization_id`
- Invalid Bearer key (validateApiKey returns null) returns 401 JSON with `"Invalid API key."`

Example pattern (following `tests/Unit/EnsureWorkOSAuthenticatedMiddlewareTest.php` style):

```php
it('returns 401 when authorization header is missing', function () {
    $request = Request::create('/api/test', 'GET');
    $middleware = new ValidateApiKey;

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true))->toMatchArray(['message' => 'Unauthenticated.']);
});

it('injects organization id when key owner is an organization', function () {
    $validation = new ApiKeyValidation(
        id: 'key_123',
        ownerType: 'organization',
        ownerId: 'org_456',
        name: 'Test Key',
        obfuscatedValue: 'sk_***',
        permissions: [],
        organizationId: 'org_456',
        lastUsedAt: null,
        createdAt: '2024-01-01T00:00:00Z',
        updatedAt: '2024-01-01T00:00:00Z',
    );

    \WorkOS\AuthKit\Facades\WorkOS::shouldReceive('validateApiKey')
        ->with('sk_test_valid_key')
        ->andReturn($validation);

    $request = Request::create('/api/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer sk_test_valid_key',
    ]);

    $middleware = new ValidateApiKey;
    $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

    expect($request->attributes->get('workos_organization_id'))->toBe('org_456');
});
```

### Unit Tests (`tests/Unit/ApiKeyUserProviderTest.php`)

**Key test cases**:
- `retrieveByToken()` with valid key calls `validateApiKey()` and returns user by `ownerId`
- `retrieveByToken()` with invalid key returns `null`
- `retrieveById()` looks up user by `workos_id`
- `retrieveByCredentials()` always returns `null`
- `validateCredentials()` always returns `false`

### Feature Tests (`tests/Feature/ApiKeyAuthTest.php`)

Register a test route in the test bootstrap that uses `workos.apikey` middleware, then:

**Key test cases**:
- `GET /api/test` with valid `Authorization: Bearer <key>` returns 200
- `GET /api/test` with no `Authorization` header returns 401
- `GET /api/test` with invalid key returns 401 with `"Invalid API key."`
- `GET /api/test` with valid org-owned key has `workos_organization_id` available in the request

## Validation Commands

```bash
composer analyse
vendor/bin/pest tests/Unit/ValidateApiKeyTest.php tests/Unit/ApiKeyMiddlewareTest.php tests/Unit/ApiKeyUserProviderTest.php tests/Feature/ApiKeyAuthTest.php
composer test
```
