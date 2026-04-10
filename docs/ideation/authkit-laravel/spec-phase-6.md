# Implementation Spec: AuthKit Laravel - Phase 6

**PRD**: ./prd-phase-6.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 6 completes the package with testing utilities, Inertia.js integration, and an example application. `WorkOSFake` provides a complete test double with fluent configuration and assertions. The Inertia middleware shares auth state to frontend applications.

The example application (separate repository) demonstrates full integration with both Blade and Inertia stacks.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Testing/WorkOSFake.php` | Test double with assertions |
| `src/Testing/Concerns/InteractsWithWorkOS.php` | Test helper trait |
| `src/Http/Middleware/ShareWorkOSData.php` | Inertia data sharing |
| `src/Commands/PruneSessionsCommand.php` | Session cleanup |
| `tests/Unit/WorkOSFakeTest.php` | Fake tests |
| `tests/Feature/InertiaMiddlewareTest.php` | Inertia tests |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOS.php` | Add `fake()`, `actingAs()`, `restore()` static methods |
| `src/WorkOSServiceProvider.php` | Register Inertia middleware alias |

## Implementation Details

### WorkOSFake

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\Assert;
use WorkOS\AuthKit\Auth\WorkOSSession;

class WorkOSFake
{
    private ?Authenticatable $user = null;
    private array $roles = [];
    private array $permissions = [];
    private ?string $organizationId = null;
    private ?array $impersonator = null;
    private array $auditedEvents = [];

    public function actingAs(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
    ): static {
        $this->user = $user;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->organizationId = $organizationId;

        $session = $this->buildSession();

        if (method_exists($user, 'setWorkOSSession')) {
            $user->setWorkOSSession($session);
        }

        auth()->login($user);

        return $this;
    }

    public function withRoles(array $roles): static
    {
        $this->roles = array_merge($this->roles, $roles);
        $this->refreshSession();
        return $this;
    }

    public function withPermissions(array $permissions): static
    {
        $this->permissions = array_merge($this->permissions, $permissions);
        $this->refreshSession();
        return $this;
    }

    public function inOrganization(string $organizationId): static
    {
        $this->organizationId = $organizationId;
        $this->refreshSession();
        return $this;
    }

    public function impersonating(array $impersonator): static
    {
        $this->impersonator = $impersonator;
        $this->refreshSession();
        return $this;
    }

    // Service method overrides
    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function session(): ?WorkOSSession
    {
        return $this->user ? $this->buildSession() : null;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isImpersonating(): bool
    {
        return $this->impersonator !== null;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function audit(string $action, array $targets = [], array $metadata = []): void
    {
        $this->auditedEvents[] = compact('action', 'targets', 'metadata');
    }

    // Assertions
    public function assertAuthenticated(): static
    {
        Assert::assertNotNull($this->user, 'Expected user to be authenticated.');
        return $this;
    }

    public function assertGuest(): static
    {
        Assert::assertNull($this->user, 'Expected no authenticated user.');
        return $this;
    }

    public function assertHasRole(string $role): static
    {
        Assert::assertTrue(
            $this->hasRole($role),
            "Expected user to have role [{$role}]."
        );
        return $this;
    }

    public function assertHasPermission(string $permission): static
    {
        Assert::assertTrue(
            $this->hasPermission($permission),
            "Expected user to have permission [{$permission}]."
        );
        return $this;
    }

    public function assertInOrganization(string $orgId): static
    {
        Assert::assertEquals(
            $orgId,
            $this->organizationId,
            "Expected organization [{$orgId}], got [{$this->organizationId}]."
        );
        return $this;
    }

    public function assertAudited(string $action, ?callable $callback = null): static
    {
        $matching = array_filter($this->auditedEvents, fn ($e) => $e['action'] === $action);

        Assert::assertNotEmpty($matching, "Expected audit event [{$action}] was not logged.");

        if ($callback) {
            foreach ($matching as $event) {
                if ($callback($event)) {
                    return $this;
                }
            }
            Assert::fail("Audit event [{$action}] logged but callback returned false.");
        }

        return $this;
    }

    public function assertNotAudited(string $action): static
    {
        $matching = array_filter($this->auditedEvents, fn ($e) => $e['action'] === $action);
        Assert::assertEmpty($matching, "Unexpected audit event [{$action}] was logged.");
        return $this;
    }

    public function assertAuditedCount(int $count): static
    {
        Assert::assertCount($count, $this->auditedEvents);
        return $this;
    }

    private function buildSession(): WorkOSSession
    {
        return new WorkOSSession(
            userId: $this->user?->getWorkOSId() ?? 'fake_user_id',
            accessToken: 'fake_access_token',
            refreshToken: 'fake_refresh_token',
            expiresAt: now()->addHour(),
            sessionId: 'fake_session_id',
            roles: $this->roles,
            permissions: $this->permissions,
            organizationId: $this->organizationId,
            impersonator: $this->impersonator,
        );
    }

    private function refreshSession(): void
    {
        if ($this->user && method_exists($this->user, 'setWorkOSSession')) {
            $this->user->setWorkOSSession($this->buildSession());
        }
    }
}
```

### WorkOS Static Methods

Add to `src/WorkOS.php`:

```php
private static ?WorkOSFake $fake = null;

public static function fake(): WorkOSFake
{
    static::$fake = new WorkOSFake();
    app()->instance('workos', static::$fake);
    return static::$fake;
}

public static function actingAs(
    Authenticatable $user,
    array $roles = [],
    array $permissions = [],
    ?string $organizationId = null,
): WorkOSFake {
    return static::fake()->actingAs($user, $roles, $permissions, $organizationId);
}

public static function isFaked(): bool
{
    return static::$fake !== null;
}

public static function restore(): void
{
    static::$fake = null;
    app()->forgetInstance('workos');
}
```

### ShareWorkOSData Middleware (Inertia)

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use WorkOS\AuthKit\Auth\SessionManager;

class ShareWorkOSData
{
    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (!class_exists(Inertia::class)) {
            return $next($request);
        }

        Inertia::share([
            'auth' => fn () => $this->getAuthData($request),
        ]);

        return $next($request);
    }

    private function getAuthData(Request $request): array
    {
        $user = $request->user();
        $session = $this->session->getSession();

        if (!$user) {
            return [
                'check' => false,
                'user' => null,
                'roles' => [],
                'permissions' => [],
                'organization' => null,
                'impersonating' => false,
            ];
        }

        return [
            'check' => true,
            'user' => [
                'id' => $user->id,
                'workos_id' => $user->getWorkOSId(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'roles' => $session?->roles ?? [],
            'permissions' => $session?->permissions ?? [],
            'organization' => $session?->organizationId,
            'impersonating' => $session?->impersonator !== null,
            'impersonator' => $session?->impersonator,
        ];
    }
}
```

### PruneSessionsCommand

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneSessionsCommand extends Command
{
    protected $signature = 'workos:prune-sessions
        {--hours=24 : Delete sessions older than this many hours}';

    protected $description = 'Prune expired WorkOS sessions';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        // This assumes sessions are stored in database
        // Adjust based on session driver
        $deleted = DB::table('sessions')
            ->where('last_activity', '<', now()->subHours($hours)->timestamp)
            ->delete();

        $this->info("Pruned {$deleted} expired sessions.");

        return self::SUCCESS;
    }
}
```

### InteractsWithWorkOS Test Trait

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Testing\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\AuthKit\Testing\WorkOSFake;

trait InteractsWithWorkOS
{
    protected function actingAsWorkOS(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
    ): WorkOSFake {
        return WorkOS::actingAs($user, $roles, $permissions);
    }

    protected function fakeWorkOS(): WorkOSFake
    {
        return WorkOS::fake();
    }

    protected function tearDownWorkOS(): void
    {
        WorkOS::restore();
    }
}
```

## Example Application (Separate Repository)

Create `workos/authkit-laravel-example` with:

```
authkit-laravel-example/
├── app/
│   └── Models/
│       └── User.php              # With HasWorkOSId, HasWorkOSPermissions traits
├── resources/
│   └── views/
│       ├── layouts/app.blade.php
│       ├── dashboard.blade.php   # Shows user info, permissions
│       ├── admin.blade.php       # Role-protected page
│       └── components/
│           └── impersonation-banner.blade.php
├── routes/
│   └── web.php                   # Demo routes with middleware
├── tests/
│   └── Feature/
│       ├── AuthenticationTest.php
│       ├── AuthorizationTest.php
│       └── AuditLoggingTest.php
└── README.md                     # Getting started guide
```

**Key demonstrations**:
- Complete login/logout flow
- Role-based route protection
- Permission-based UI conditionals
- Team switching
- Audit logging
- Both Blade and Inertia versions

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/WorkOSFakeTest.php` | Fake service, assertions |
| `tests/Unit/ShareWorkOSDataTest.php` | Inertia middleware |

**Key test cases**:
- `WorkOS::fake()` replaces service
- `actingAs()` sets up user with roles/permissions
- `withRoles()` adds roles incrementally
- `assertAudited()` verifies audit events
- `assertHasRole()` verifies role presence
- Inertia middleware shares correct data structure
- Middleware handles unauthenticated state

### Feature Tests

**Key scenarios**:
- Full auth flow with faked WorkOS
- Role-protected routes with fake
- Permission checks with fake
- Audit assertions in tests

## Validation Commands

```bash
# All tests
./vendor/bin/pest

# Coverage report
./vendor/bin/pest --coverage --min=90

# Style check
./vendor/bin/pint --test

# Static analysis
./vendor/bin/phpstan analyse src --level=8
```

## Rollout Considerations

- **Package Release**: Tag v1.0.0 after all phases complete
- **Example App**: Release simultaneously with package
- **Documentation**: README serves as primary docs via example app
- **Packagist**: Submit to Packagist for `composer require`

---

*This spec is ready for implementation.*
