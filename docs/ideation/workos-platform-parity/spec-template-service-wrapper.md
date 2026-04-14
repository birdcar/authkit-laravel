# Spec Template: WorkOS Service Wrapper Phase

**Used by**: Phase 7 (Vault), Phase 8 (Radar), Phase 9 (Pipes), Phase 10 (Domain Verification)

Each delta spec references this template and provides the values for every placeholder. Read both files together when implementing.

---

## Technical Approach

Create a Laravel service class that wraps the `{ServiceName}` WorkOS API endpoints and register it on the `WorkOS` facade. The service is a plain PHP class — no Laravel base class — that uses the WorkOS PHP SDK's HTTP client or raw Guzzle (via `workos()->httpClient()`) to call the API. It is registered as a lazily-resolved singleton in `WorkOSServiceProvider`.

Optionally, if the service has request-time concerns (validating tokens, enriching requests), a middleware class is added to `src/Http/Middleware/`.

The service is opt-in: `config('workos.features.{config_key}', false)` guards service provider registration and the `WorkOS::{serviceName}()` method throws `\RuntimeException` when the feature is disabled, matching the existing audit log guard pattern.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/{ServiceName}ServiceTest.php`

**Playground**: Test suite — mock HTTP responses using Guzzle's `MockHandler` or Laravel's `Http::fake()`.

**Why this approach**: Service classes have no framework dependencies beyond constructor injection. PHPStan and Pest give fast feedback without needing a running server or live WorkOS credentials.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Services/{ServiceName}.php` | Service class wrapping `{api_endpoints}` |
| `src/Http/Middleware/{MiddlewareName}.php` | *(Optional)* Middleware for request-time concerns |
| `tests/Unit/{ServiceName}ServiceTest.php` | Unit tests for service methods |
| `tests/Feature/{MiddlewareName}Test.php` | *(Optional)* Feature tests for middleware |

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOS.php` | Add `{serviceName}()` method and `SERVICE_MAP` entry |
| `src/Facades/WorkOS.php` | Add `@method static {ServiceName} {serviceName}()` docblock |
| `src/WorkOSServiceProvider.php` | Register service singleton; bind middleware alias |
| `config/workos.php` | Add `features.{config_key}` toggle key |

## Implementation Details

### Service Class — `src/Services/{ServiceName}.php`

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

class {ServiceName}
{
    // {method_signatures}
    // Each method maps to one WorkOS API endpoint.
    // Throw \RuntimeException on API errors; let callers decide recovery.
}
```

**Conventions**:
- Constructor receives no dependencies by default; use `app('workos.http')` or the WorkOS SDK client if needed
- Method names are camelCase verbs matching the API action: `create()`, `get()`, `list()`, `update()`, `delete()`
- Return types are `array<string, mixed>` for raw API responses; add typed value objects if the delta spec requests them
- PHPDoc `@param` and `@return` blocks required for all public methods (PHPStan level 8)
- No facades used inside the service — pure dependency injection

### WorkOS.php — Service Registration

```php
// Add to SERVICE_MAP constant:
'{serviceName}' => {ServiceName}::class,

// Add typed method for IDE support (prevents __call for known services):
public function {serviceName}(): {ServiceName}
{
    if (! config('workos.features.{config_key}', false)) {
        throw new \RuntimeException('WorkOS {ServiceName} is not enabled. Set WORKOS_FEATURE_{CONFIG_KEY_UPPER}=true.');
    }

    /** @var {ServiceName} */
    return $this->instances['{serviceName}'] ??= new {ServiceName};
}
```

### Facades/WorkOS.php — Docblock

```php
// Add to @method docblock block:
 * @method static {ServiceName} {serviceName}()
```

### Middleware — `src/Http/Middleware/{MiddlewareName}.php`

*(Include only when the delta spec defines a middleware.)*

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class {MiddlewareName}
{
    public function handle(Request $request, Closure $next, {middleware_params}): Response
    {
        // {middleware_logic}

        return $next($request);
    }
}
```

Register in `WorkOSServiceProvider::configureMiddleware()`:

```php
$router->aliasMiddleware('workos.{middleware_alias}', {MiddlewareName}::class);
```

### Config — workos.php

```php
// In the 'features' array:
'{config_key}' => env('WORKOS_FEATURE_{CONFIG_KEY_UPPER}', false),
```

## Testing Requirements

### Unit Tests — `tests/Unit/{ServiceName}ServiceTest.php`

**Required test cases**:

- Each public method: happy path returns expected array/value
- Each public method: throws on API error (mock HTTP 4xx/5xx)
- Service is disabled: calling `WorkOS::{serviceName}()` throws `\RuntimeException`
- PHPStan passes on the service class (enforced by `composer analyse`)

**Test structure** (Pest):

```php
it('{serviceName} returns {expected} for {method}()', function () {
    Http::fake(['{endpoint}' => Http::response([/* fixture */], 200)]);

    $result = (new {ServiceName})->{method}({args});

    expect($result)->toHaveKey('{key}');
});
```

### Feature Tests — `tests/Feature/{MiddlewareName}Test.php`

*(Include only when a middleware is defined.)*

**Required test cases**:

- Request passes through when {condition}
- Request is rejected with {status_code} when {condition}
- Correct response headers/body are set

## Validation Commands

```bash
composer analyse
composer test
```

---

## Placeholder Reference

| Placeholder | Description |
|---|---|
| `{ServiceName}` | PascalCase class name (e.g. `VaultService`) |
| `{serviceName}` | camelCase facade method name (e.g. `vault`) |
| `{config_key}` | snake_case key in `workos.features` (e.g. `vault`) |
| `{CONFIG_KEY_UPPER}` | SCREAMING_SNAKE for env var (e.g. `VAULT`) |
| `{api_endpoints}` | List of HTTP method + path pairs wrapped |
| `{MiddlewareName}` | PascalCase middleware class name (omit section if none) |
| `{middleware_alias}` | Middleware alias string used in route definitions |
| `{middleware_params}` | Additional `handle()` parameters beyond `$request/$next` |
| `{middleware_logic}` | Description of what the middleware does |
| `{method_signatures}` | Public method signatures for the service class |
