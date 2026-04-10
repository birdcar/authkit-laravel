# Implementation Spec: AuthKit Laravel - Phase 2

**PRD**: ./prd-phase-2.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 2 implements the authorization layer using Laravel's native patterns. Model traits add WorkOS capabilities to Eloquent models using Laravel's trait initialization system. Middleware follows Laravel's pipeline pattern with parameter support for flexible route protection.

Blade directives will be registered via `Blade::if()` for the simple conditional patterns and compile to efficient PHP. Auth routes will use Laravel's route registration with configurable prefixes and middleware, following Sanctum's route definition pattern.

Events follow Laravel's event/listener architecture, allowing users to hook into auth lifecycle without modifying package code.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Models/Concerns/HasWorkOSId.php` | Trait for workos_id functionality |
| `src/Models/Concerns/HasWorkOSPermissions.php` | Trait for roles/permissions |
| `src/Http/Controllers/AuthController.php` | Login, callback, logout handlers |
| `src/Http/Middleware/EnsureWorkOSAuthenticated.php` | Auth check middleware |
| `src/Http/Middleware/CheckRole.php` | Role verification middleware |
| `src/Http/Middleware/CheckPermission.php` | Permission verification middleware |
| `src/Http/Middleware/DetectImpersonation.php` | Impersonation detection middleware |
| `src/Events/UserAuthenticated.php` | Login event |
| `src/Events/UserLoggedOut.php` | Logout event |
| `src/Exceptions/MissingRoleException.php` | Role check failure |
| `src/Exceptions/MissingPermissionException.php` | Permission check failure |
| `routes/web.php` | Auth route definitions |
| `tests/Unit/HasWorkOSIdTest.php` | Trait unit tests |
| `tests/Unit/HasWorkOSPermissionsTest.php` | Trait unit tests |
| `tests/Unit/MiddlewareTest.php` | Middleware unit tests |
| `tests/Feature/AuthFlowTest.php` | Full auth flow tests |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOSServiceProvider.php` | Add middleware registration, route loading, Blade directives |

## Implementation Details

### HasWorkOSId Trait

**Pattern to follow**: `laravel/framework` traits like `HasUuids`

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

trait HasWorkOSId
{
    public function initializeHasWorkOSId(): void
    {
        $this->mergeFillable([$this->getWorkOSIdColumn()]);
    }

    public function getWorkOSIdColumn(): string
    {
        return 'workos_id';
    }

    public function getWorkOSId(): ?string
    {
        return $this->{$this->getWorkOSIdColumn()};
    }

    public static function findByWorkOSId(string $workosId): ?static
    {
        return static::where((new static)->getWorkOSIdColumn(), $workosId)->first();
    }

    public static function findOrCreateByWorkOS(array $workosUser): static
    {
        $model = new static;

        return static::firstOrCreate(
            [$model->getWorkOSIdColumn() => $workosUser['id']],
            [
                'email' => $workosUser['email'],
                'name' => trim(($workosUser['first_name'] ?? '') . ' ' . ($workosUser['last_name'] ?? '')),
            ]
        );
    }
}
```

**Implementation steps**:
1. Create trait with `initializeHasWorkOSId()` for automatic fillable
2. Add `getWorkOSIdColumn()` for customization
3. Implement static finders `findByWorkOSId()` and `findOrCreateByWorkOS()`

### HasWorkOSPermissions Trait

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use WorkOS\AuthKit\Auth\WorkOSSession;

trait HasWorkOSPermissions
{
    protected ?WorkOSSession $workosSession = null;

    public function setWorkOSSession(WorkOSSession $session): void
    {
        $this->workosSession = $session;
    }

    public function getWorkOSSession(): ?WorkOSSession
    {
        return $this->workosSession;
    }

    public function hasWorkOSRole(string $role): bool
    {
        return in_array($role, $this->workosSession?->roles ?? [], true);
    }

    public function hasWorkOSPermission(string $permission): bool
    {
        return in_array($permission, $this->workosSession?->permissions ?? [], true);
    }

    public function hasAnyWorkOSRole(array $roles): bool
    {
        return !empty(array_intersect($roles, $this->workosSession?->roles ?? []));
    }

    public function hasAllWorkOSPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->workosSession?->permissions ?? []));
    }

    public function currentOrganizationId(): ?string
    {
        return $this->workosSession?->organizationId;
    }

    public function isImpersonating(): bool
    {
        return $this->workosSession?->impersonator !== null;
    }

    public function impersonator(): ?array
    {
        return $this->workosSession?->impersonator;
    }
}
```

**Implementation steps**:
1. Add session storage property and setter
2. Implement role checking methods
3. Implement permission checking methods
4. Add organization and impersonation helpers

### Middleware - CheckRole

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Exceptions\MissingRoleException;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            throw new MissingRoleException($roles, 'Unauthenticated');
        }

        if (!method_exists($user, 'hasAnyWorkOSRole')) {
            throw new MissingRoleException($roles, 'User model missing HasWorkOSPermissions trait');
        }

        if (!$user->hasAnyWorkOSRole($roles)) {
            throw new MissingRoleException($roles);
        }

        return $next($request);
    }
}
```

**Implementation steps**:
1. Create middleware accepting variadic role parameters
2. Check user is authenticated
3. Verify user has HasWorkOSPermissions trait
4. Check any role matches, throw exception if not

### AuthController

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Events\UserAuthenticated;
use WorkOS\AuthKit\Events\UserLoggedOut;
use WorkOS\UserManagement;

class AuthController
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly UserManagement $userManagement,
    ) {}

    public function login(Request $request): RedirectResponse
    {
        $url = $this->userManagement->getAuthorizationUrl(
            redirectUri: config('workos.redirect_uri'),
            clientId: config('workos.client_id'),
            provider: 'authkit',
            state: csrf_token(),
            organizationId: $request->query('organization'),
        );

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');

        $response = $this->userManagement->authenticateWithCode(
            clientId: config('workos.client_id'),
            code: $code,
        );

        $session = $this->session->store($response);

        $userModel = config('workos.user_model');
        $user = $userModel::findOrCreateByWorkOS($response['user']);

        event(new UserAuthenticated($user, $session));

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        $session = $this->session->getSession();
        $user = $request->user();

        $this->session->destroy();

        if ($user) {
            event(new UserLoggedOut($user, $session));
        }

        $logoutUrl = $this->userManagement->getLogoutUrl(
            sessionId: $session?->sessionId,
        );

        return redirect()->away($logoutUrl);
    }
}
```

**Implementation steps**:
1. Create controller with dependency injection
2. Implement `login()` to redirect to WorkOS
3. Implement `callback()` to handle OAuth response
4. Implement `logout()` to destroy session and redirect

### Blade Directives

Add to `WorkOSServiceProvider::boot()`:

```php
protected function configureBladeDirectives(): void
{
    Blade::if('workosRole', fn (string $role) =>
        auth()->user()?->hasWorkOSRole($role) ?? false
    );

    Blade::if('workosPermission', fn (string $permission) =>
        auth()->user()?->hasWorkOSPermission($permission) ?? false
    );

    Blade::if('impersonating', fn () =>
        app(SessionManager::class)->isImpersonating()
    );
}
```

### Route Definitions

```php
// routes/web.php
<?php

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Http\Controllers\AuthController;

Route::get('/login', [AuthController::class, 'login'])->name('workos.login');
Route::get('/callback', [AuthController::class, 'callback'])->name('workos.callback');
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:workos')
    ->name('workos.logout');
```

## API Design

### Auth Routes

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/auth/login` | Redirect to WorkOS authorization |
| `GET` | `/auth/callback` | Handle OAuth callback |
| `POST` | `/auth/logout` | Logout and destroy session |

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/HasWorkOSIdTest.php` | Trait methods, static finders |
| `tests/Unit/HasWorkOSPermissionsTest.php` | Role/permission checks |
| `tests/Unit/CheckRoleMiddlewareTest.php` | Role middleware logic |
| `tests/Unit/CheckPermissionMiddlewareTest.php` | Permission middleware logic |

**Key test cases**:
- `findByWorkOSId()` returns correct user
- `findOrCreateByWorkOS()` creates new user
- `hasWorkOSRole()` returns true for matching role
- `hasAnyWorkOSRole()` returns true if any role matches
- Middleware passes when role present
- Middleware throws exception when role missing
- Blade directives render correctly

### Feature Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/AuthFlowTest.php` | Full login/logout flow |
| `tests/Feature/MiddlewareTest.php` | Route protection |

**Key scenarios**:
- Login redirects to WorkOS
- Callback creates session and user
- Protected route blocks unauthenticated
- Protected route allows authorized user
- Logout destroys session

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Missing role | Throw `MissingRoleException`, render 403 |
| Missing permission | Throw `MissingPermissionException`, render 403 |
| Invalid OAuth code | Redirect to login with error flash |
| Missing trait on model | Throw descriptive exception |

## Validation Commands

```bash
# Unit tests for Phase 2
./vendor/bin/pest tests/Unit/HasWorkOS*
./vendor/bin/pest tests/Unit/*Middleware*

# Feature tests
./vendor/bin/pest tests/Feature/AuthFlowTest.php
./vendor/bin/pest tests/Feature/MiddlewareTest.php
```

---

*This spec is ready for implementation.*
