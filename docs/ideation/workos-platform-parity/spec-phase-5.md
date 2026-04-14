# Implementation Spec: Platform Parity - Phase 5 (FGA)

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

WorkOS FGA (Fine-Grained Authorization) provides resource-level access control: users are assigned roles on specific resources (e.g., `viewer` on `project:proj_123`), and access checks determine whether a user has a given permission on a resource. This is a Zanzibar-style model — distinct from the org-level RBAC already in the package.

The WorkOS PHP SDK does not ship a dedicated `FGA` service class as of `^4.29`. The FGA API is a REST surface (`/fga/v1/resources`, `/fga/v1/role-assignments`, `/fga/v1/access-checks`) that is not yet wrapped in the SDK. This spec accounts for that gap: `FGAService` will make direct HTTP calls via the WorkOS `Client` until the SDK adds a native class. When the SDK adds FGA support, the service can be updated to delegate to it.

**Core data model**:
- **Resource**: an application entity identified by `resourceType` (e.g., `"project"`) and `resourceId` (e.g., `"proj_123"`)
- **Role assignment**: connects a `userId` to a `roleSlug` on a specific resource
- **Access check**: answers "does `userId` have `permission` on `resource`?"
- **Permissions inherit down the resource hierarchy** — if a user is `admin` on a workspace, they implicitly have access to all projects within it

**Laravel integration strategy**: Register a custom `Gate` that delegates to FGA for `Gate::allows()` calls. This lets existing Laravel authorization patterns (`$this->authorize()`, `@can()`, policy classes) work transparently against WorkOS FGA without app code changes. The Gate integration is opt-in — it does not override the default Gate globally; it registers an `after` hook so app-defined policies still take precedence.

**Per-request caching**: FGA access checks hit an external API. The same `(userId, permission, resourceType, resourceId)` tuple must not trigger more than one API call per request. Cache results in a per-request array keyed by a hash of the tuple.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/FGAServiceTest.php tests/Feature/CheckFGATest.php`

**Playground**: Test suite. `FGAService` wraps the WorkOS `Client` directly, so tests mock `\WorkOS\Client` or use `Http::fake()` patterns depending on how the client is abstracted. Given PHPStan level 8, the client interaction must be typed carefully.

**Why this approach**: FGA involves multiple orthogonal pieces (service, gate, middleware, directive, command). The inner loop isolates the service and middleware first; Gate integration and the artisan command are validated via feature tests.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/FGA/FGAService.php` | Core service: resources, role assignments, access checks, per-request cache |
| `src/FGA/FGAResource.php` | Value object representing a resource (`resourceType` + `resourceId`) |
| `src/FGA/FGAAccessResult.php` | Value object for an access check result (`bool $allowed`, cached metadata) |
| `src/Http/Middleware/CheckFGAAccess.php` | `workos.fga` middleware for route-level resource access |
| `src/Commands/FGACheckCommand.php` | `workos:fga-check` artisan command for debugging |
| `tests/Unit/FGAServiceTest.php` | Unit tests for service logic and per-request cache |
| `tests/Feature/CheckFGATest.php` | Feature tests for middleware and Gate integration |

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOS.php` | Add `fga()` method returning `FGAService` singleton |
| `src/Facades/WorkOS.php` | Add `@method` docblock for `fga()` |
| `src/WorkOSServiceProvider.php` | Register `FGAService` singleton; register `workos.fga` middleware alias; register Gate `after` hook if FGA enabled; register `workos:fga-check` command; register `@workosAccess` Blade directive |
| `config/workos.php` | Add `fga` section: `enabled`, `gate_integration`, `cache_store` |

## Implementation Details

### FGAResource Value Object

**Pattern to follow**: `src/Auth/WorkOSSession.php` (readonly value object)

```php
namespace WorkOS\AuthKit\FGA;

readonly class FGAResource
{
    public function __construct(
        public string $resourceType,
        public string $resourceId,
    ) {}

    public function toString(): string
    {
        return "{$this->resourceType}:{$this->resourceId}";
    }

    public static function fromString(string $resource): self
    {
        [$type, $id] = explode(':', $resource, 2);

        return new self(resourceType: $type, resourceId: $id);
    }
}
```

The `resourceType:resourceId` string format matches WorkOS API conventions and makes it convenient to pass resources as route parameters or Blade arguments.

### FGAAccessResult Value Object

```php
namespace WorkOS\AuthKit\FGA;

readonly class FGAAccessResult
{
    public function __construct(
        public bool $allowed,
        public string $userId,
        public string $permission,
        public FGAResource $resource,
    ) {}
}
```

### FGAService

**Pattern to follow**: `src/Audit/AuditLogger.php` for constructor injection; `src/Auth/SessionManager.php` for per-instance caching pattern.

```php
namespace WorkOS\AuthKit\FGA;

use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\Client;

class FGAService
{
    /** @var array<string, FGAAccessResult> */
    private array $checkCache = [];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function check(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): bool {
        $cacheKey = $this->cacheKey($userId, $permission, $resourceType, $resourceId);

        if (isset($this->checkCache[$cacheKey])) {
            return $this->checkCache[$cacheKey]->allowed;
        }

        $result = $this->performCheck($userId, $permission, $resourceType, $resourceId);
        $this->checkCache[$cacheKey] = $result;

        return $result->allowed;
    }

    public function checkForCurrentUser(
        string $permission,
        string $resourceType,
        string $resourceId,
    ): bool {
        $session = $this->session->getSession();
        if ($session === null) {
            return false;
        }

        return $this->check($session->userId, $permission, $resourceType, $resourceId);
    }

    /**
     * @return array<FGAResource>
     */
    public function listResources(
        string $userId,
        string $permission,
        string $resourceType,
    ): array {
        try {
            $response = Client::request(
                Client::METHOD_GET,
                'fga/v1/access-checks/resources',
                null,
                [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'resource_type' => $resourceType,
                ],
                true,
            );

            return array_map(
                fn (array $r) => new FGAResource($r['resource_type'], $r['resource_id']),
                $response['data'] ?? [],
            );
        } catch (\Exception) {
            return [];
        }
    }

    public function assign(
        string $userId,
        string $roleSlug,
        string $resourceType,
        string $resourceId,
    ): bool {
        try {
            Client::request(
                Client::METHOD_POST,
                'fga/v1/role-assignments',
                null,
                [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $this->flushCache($userId);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function unassign(
        string $userId,
        string $roleSlug,
        string $resourceType,
        string $resourceId,
    ): bool {
        try {
            Client::request(
                Client::METHOD_DELETE,
                'fga/v1/role-assignments',
                null,
                [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $this->flushCache($userId);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function flushCache(?string $userId = null): void
    {
        if ($userId === null) {
            $this->checkCache = [];

            return;
        }

        foreach (array_keys($this->checkCache) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->checkCache[$key]);
            }
        }
    }

    private function performCheck(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): FGAAccessResult {
        try {
            $response = Client::request(
                Client::METHOD_POST,
                'fga/v1/access-checks',
                null,
                [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ],
                true,
            );

            $allowed = (bool) ($response['allowed'] ?? false);
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

    private function cacheKey(
        string $userId,
        string $permission,
        string $resourceType,
        string $resourceId,
    ): string {
        return "{$userId}:{$permission}:{$resourceType}:{$resourceId}";
    }
}
```

**Key decisions**:
- Per-request cache is an in-memory array on the singleton — cleared automatically per request since Laravel re-resolves singletons per request cycle
- `flushCache()` is called after `assign()`/`unassign()` so the next `check()` reflects the new state
- Silent exception catches throughout — FGA must not crash page renders; callers get `false` on failure
- `checkForCurrentUser()` is the ergonomic shortcut for the common case of checking the logged-in user

### WorkOS::fga()

```php
public function fga(): FGAService
{
    return $this->instances['fga'] ??= app(FGAService::class);
}
```

### Gate Integration

Register an `after` hook so FGA runs only when no earlier Gate policy returned a definitive result. This preserves existing application Gate/Policy definitions while adding FGA as the fallback.

```php
// In WorkOSServiceProvider::boot(), inside configureFGA():
if (config('workos.fga.gate_integration', false)) {
    Gate::after(function (Authenticatable $user, string $ability, ?bool $result, mixed $arguments) {
        // Only run FGA if no policy gave a definitive answer
        if ($result !== null) {
            return $result;
        }

        $resource = $arguments[0] ?? null;
        if (! $resource instanceof FGAResource) {
            return null;
        }

        $userId = method_exists($user, 'getWorkOSId') ? $user->getWorkOSId() : (string) $user->getAuthIdentifier();

        return app(FGAService::class)->check(
            userId: $userId,
            permission: $ability,
            resourceType: $resource->resourceType,
            resourceId: $resource->resourceId,
        );
    });
}
```

**Usage in application code** (once gate integration is on):
```php
// In a controller:
$this->authorize('edit', new FGAResource('project', $project->workos_id));

// In Blade:
@can('edit', new \WorkOS\AuthKit\FGA\FGAResource('project', $project->workos_id))
    <button>Edit</button>
@endcan
```

**Key decision**: Gate integration is `false` by default. It requires an explicit opt-in because it changes how Gate resolves unknown abilities, which could surprise developers who aren't expecting FGA to be consulted. Apps that want FGA-backed Gate simply set `workos.fga.gate_integration=true`.

### CheckFGAAccess Middleware

**Pattern to follow**: `src/Http/Middleware/CheckPermission.php`

```php
namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\FGA\FGAResource;
use WorkOS\AuthKit\FGA\FGAService;

class CheckFGAAccess
{
    public function __construct(private readonly FGAService $fga) {}

    /**
     * Handle FGA access check for route-level protection.
     *
     * Signature: workos.fga:permission,resourceType,resourceId
     * The resourceId can be a route parameter name prefixed with ':' (e.g., ':projectId')
     * or a literal value.
     */
    public function handle(Request $request, Closure $next, string $permission, string $resourceType, string $resourceId): Response
    {
        // Resolve route parameter references (e.g., ':projectId' -> $request->route('projectId'))
        if (str_starts_with($resourceId, ':')) {
            $paramName = substr($resourceId, 1);
            $resourceId = (string) $request->route($paramName);
        }

        if (! $this->fga->checkForCurrentUser($permission, $resourceType, $resourceId)) {
            abort(403, "Access denied: [{$permission}] on [{$resourceType}:{$resourceId}].");
        }

        return $next($request);
    }
}
```

Usage:
```php
// Literal resource ID:
Route::get('/projects/{project}', ProjectController::class)
    ->middleware('workos.fga:view,project,proj_123');

// Dynamic resource ID from route parameter:
Route::get('/projects/{projectId}', ProjectController::class)
    ->middleware('workos.fga:view,project,:projectId');
```

The `:paramName` convention for dynamic resource IDs avoids needing to put authorization logic in the controller for simple cases.

### @workosAccess Blade Directive

```php
// In WorkOSServiceProvider::configureBladeDirectives():
Blade::if('workosAccess', function (string $permission, FGAResource $resource) {
    return app(FGAService::class)->checkForCurrentUser(
        permission: $permission,
        resourceType: $resource->resourceType,
        resourceId: $resource->resourceId,
    );
});
```

Usage in Blade:
```blade
@workosAccess('edit', new \WorkOS\AuthKit\FGA\FGAResource('project', $project->workos_id))
    <a href="/projects/{{ $project->id }}/edit">Edit</a>
@endworkosAccess
```

### workos:fga-check Artisan Command

**Pattern to follow**: `src/Commands/SyncUsersCommand.php`

```php
namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\FGA\FGAService;

class FGACheckCommand extends Command
{
    protected $signature = 'workos:fga-check
        {userId : WorkOS user ID}
        {permission : Permission slug to check}
        {resource : Resource in resourceType:resourceId format}';

    protected $description = 'Check if a user has a permission on a WorkOS FGA resource';

    public function handle(FGAService $fga): int
    {
        $resource = \WorkOS\AuthKit\FGA\FGAResource::fromString($this->argument('resource'));

        $allowed = $fga->check(
            userId: $this->argument('userId'),
            permission: $this->argument('permission'),
            resourceType: $resource->resourceType,
            resourceId: $resource->resourceId,
        );

        if ($allowed) {
            $this->components->info("Access GRANTED.");
        } else {
            $this->components->warn("Access DENIED.");
        }

        return $allowed ? Command::SUCCESS : Command::FAILURE;
    }
}
```

Usage:
```bash
php artisan workos:fga-check user_01abc view project:proj_123
# Access GRANTED.
```

The command returns exit code 0 on success and 1 on denial, making it scriptable.

### Config

```php
// In config/workos.php:
'fga' => [
    'enabled' => env('WORKOS_FGA_ENABLED', false),
    'gate_integration' => env('WORKOS_FGA_GATE_INTEGRATION', false),
],
```

Both toggles default to `false` — FGA is a significant opt-in. The `enabled` toggle gates the middleware and Blade directive registration. `gate_integration` gates the Gate `after` hook separately so apps can use FGA via `WorkOS::fga()` directly without enabling the global Gate hook.

### Service Registration

```php
// In WorkOSServiceProvider::register():
$this->app->singleton(FGAService::class, function ($app) {
    return new FGAService($app->make(SessionManager::class));
});
```

```php
// In WorkOSServiceProvider::boot(), add configureFGA():
protected function configureFGA(): void
{
    if (! config('workos.fga.enabled', false)) {
        return;
    }

    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $router->aliasMiddleware('workos.fga', CheckFGAAccess::class);

    if (config('workos.fga.gate_integration', false)) {
        $this->registerFGAGateHook();
    }
}
```

Call `$this->configureFGA()` from `boot()` alongside the other `configure*()` calls.

## Testing Requirements

### Unit Tests

**File**: `tests/Unit/FGAServiceTest.php`

**Key test cases**:
- `check()` makes an API call and returns `true` when the response `allowed` is `true`
- `check()` returns `false` when the response `allowed` is `false`
- `check()` returns `false` and does not throw when the API call throws an exception
- `check()` returns the cached result on the second call without making a second API request
- `flushCache()` clears the per-user cache so the next `check()` hits the API again
- `flushCache(null)` clears the entire cache
- `assign()` calls the role assignment API endpoint and flushes the user's cache
- `unassign()` calls the role assignment delete endpoint and flushes the user's cache
- `listResources()` returns an array of `FGAResource` value objects
- `listResources()` returns empty array when the API call fails
- `checkForCurrentUser()` returns `false` when no session exists
- `checkForCurrentUser()` uses `$session->userId` from the current session
- `FGAResource::fromString()` parses `resourceType:resourceId` correctly
- `FGAResource::toString()` produces `resourceType:resourceId`

### Feature Tests

**File**: `tests/Feature/CheckFGATest.php`

**Key test cases**:
- Route with `workos.fga:view,project,:projectId` returns 200 when FGA check passes
- Route with `workos.fga:view,project,:projectId` returns 403 when FGA check fails
- Route parameter resolution: `:projectId` is resolved from the request route
- Literal resource ID: `workos.fga:view,project,proj_123` does not resolve route parameters
- Unauthenticated user gets 403 (no session, `checkForCurrentUser` returns false)
- `@workosAccess('edit', $resource)` Blade directive renders content when check passes
- `@workosAccess('edit', $resource)` Blade directive hides content when check fails
- Gate `after` hook: `Gate::allows('edit', $fgaResource)` returns `true` when FGA allows (when `gate_integration` is enabled)
- Gate `after` hook does not override an existing policy result that returned `true`
- Gate `after` hook returns `null` (does not run) when the argument is not an `FGAResource` instance
- `workos:fga-check` command outputs "Access GRANTED" and exits 0 when allowed
- `workos:fga-check` command outputs "Access DENIED" and exits 1 when denied

## Validation Commands

```bash
composer analyse
composer test
```
