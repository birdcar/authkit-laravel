# Implementation Spec: AuthKit Laravel - Phase 1

**PRD**: ./prd-phase-1.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 1 establishes the Laravel package foundation following patterns from Sanctum and Jetstream. The package will be structured as a standalone Composer package with a service provider that registers all bindings, a facade for static access, and a global helper function.

The auth guard will use Laravel's `RequestGuard` pattern (like Sanctum) rather than a session-based guard. This allows us to check the WorkOS session state on each request without relying on Laravel's session-based auth infrastructure. The guard delegates user lookup to Laravel's standard user provider system, maintaining compatibility with the framework.

Session management will store the WorkOS authentication response (access token, refresh token, expiration) in Laravel's session store. The session manager will check expiration on each access and transparently refresh tokens when approaching expiry. All session lifetime logic uses the `expires_at` timestamp from WorkOS - no local duration configuration.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `composer.json` | Package definition with dependencies and autoloading |
| `config/workos.php` | Publishable configuration file |
| `database/migrations/add_workos_id_to_users_table.php` | Migration stub for workos_id column |
| `src/WorkOSServiceProvider.php` | Service provider for all bindings and boot logic |
| `src/WorkOS.php` | Main service class with SDK proxying and helpers |
| `src/Facades/WorkOS.php` | Laravel facade for static access |
| `src/helpers.php` | Global `workos()` helper function |
| `src/Auth/WorkOSGuard.php` | Custom auth guard implementation |
| `src/Auth/WorkOSUserProvider.php` | User provider that works with WorkOS IDs |
| `src/Auth/SessionManager.php` | Manages WorkOS session storage and refresh |
| `src/Auth/WorkOSSession.php` | Value object for session data |
| `src/Commands/InstallCommand.php` | Artisan install command |
| `src/Exceptions/WorkOSException.php` | Base exception class |
| `src/Exceptions/AuthenticationException.php` | Auth-specific exception |
| `tests/TestCase.php` | Base test case with Orchestra Testbench |
| `tests/Unit/WorkOSServiceTest.php` | Tests for main service class |
| `tests/Unit/SessionManagerTest.php` | Tests for session management |
| `tests/Unit/WorkOSGuardTest.php` | Tests for auth guard |
| `tests/Feature/InstallCommandTest.php` | Tests for install command |

### Modified Files

None (new package)

### Deleted Files

None (new package)

## Implementation Details

### Package Structure (composer.json)

**Overview**: Define package metadata, dependencies, and autoloading for Laravel package discovery.

```json
{
  "name": "workos/authkit-laravel",
  "description": "Laravel integration for WorkOS AuthKit",
  "license": "MIT",
  "require": {
    "php": "^8.1",
    "illuminate/contracts": "^10.0|^11.0|^12.0",
    "illuminate/support": "^10.0|^11.0|^12.0",
    "workos/workos-php": "^4.29"
  },
  "require-dev": {
    "orchestra/testbench": "^8.0|^9.0|^10.0",
    "pestphp/pest": "^2.0|^3.0",
    "laravel/pint": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "WorkOS\\AuthKit\\": "src/"
    },
    "files": [
      "src/helpers.php"
    ]
  },
  "extra": {
    "laravel": {
      "providers": [
        "WorkOS\\AuthKit\\WorkOSServiceProvider"
      ],
      "aliases": {
        "WorkOS": "WorkOS\\AuthKit\\Facades\\WorkOS"
      }
    }
  }
}
```

**Key decisions**:
- Support Laravel 10, 11, 12 via version constraints
- Use Pest for testing (Laravel's preferred test framework)
- Auto-register provider and facade via package discovery

**Implementation steps**:
1. Create `composer.json` with dependencies
2. Set up PSR-4 autoloading for `src/` directory
3. Configure Laravel package discovery in `extra` block
4. Add helpers.php to files autoload

### WorkOSServiceProvider

**Pattern to follow**: `laravel/sanctum/src/SanctumServiceProvider.php`

**Overview**: Central registration point for all package services, following Laravel's register/boot pattern.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;

class WorkOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workos.php', 'workos');

        $this->app->singleton(Auth\SessionManager::class);

        $this->app->singleton('workos', function ($app) {
            $this->configureWorkOSSdk();
            return new WorkOS($app->make(Auth\SessionManager::class));
        });

        $this->app->alias('workos', WorkOS::class);
    }

    public function boot(): void
    {
        $this->configureGuard();
        $this->configurePublishing();
        $this->configureCommands();
    }

    protected function configureWorkOSSdk(): void
    {
        $config = config('workos');
        \WorkOS\WorkOS::setApiKey($config['api_key']);
        \WorkOS\WorkOS::setClientId($config['client_id']);
        // ... additional SDK config
    }

    protected function configureGuard(): void
    {
        Auth::extend('workos', fn ($app, $name, array $config) =>
            new Auth\WorkOSGuard(
                Auth::createUserProvider($config['provider'] ?? null),
                $app->make(Auth\SessionManager::class),
                $app['request']
            )
        );
    }
}
```

**Key decisions**:
- Lazy SDK configuration (only when `workos` singleton first accessed)
- Register SessionManager as singleton for consistent state
- Use `Auth::extend()` pattern like Sanctum

**Implementation steps**:
1. Create service provider class
2. Implement `register()` with config merging and singleton bindings
3. Implement `boot()` with guard registration
4. Add publishing configuration for config and migrations
5. Register artisan commands

### WorkOS Service Class

**Pattern to follow**: `workos/workos-php-laravel` PR #69 `WorkOSService`

**Overview**: Main service providing SDK access and auth convenience methods.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

class WorkOS
{
    private array $instances = [];

    private const SERVICE_MAP = [
        'auditLogs' => \WorkOS\AuditLogs::class,
        'directorySync' => \WorkOS\DirectorySync::class,
        'organizations' => \WorkOS\Organizations::class,
        'sso' => \WorkOS\SSO::class,
        'userManagement' => \WorkOS\UserManagement::class,
        'webhook' => \WorkOS\Webhook::class,
    ];

    public function __construct(
        private readonly Auth\SessionManager $session,
    ) {}

    public function __call(string $name, array $arguments): object
    {
        if (! array_key_exists($name, self::SERVICE_MAP)) {
            throw new InvalidArgumentException("WorkOS service [{$name}] is not supported.");
        }
        return $this->instances[$name] ??= new (self::SERVICE_MAP[$name])();
    }

    public function user(): ?Authenticatable
    {
        return auth(config('workos.guard', 'workos'))->user();
    }

    public function session(): ?Auth\WorkOSSession
    {
        return $this->session->getSession();
    }

    public function loginUrl(?string $organizationId = null): string
    {
        return $this->userManagement()->getAuthorizationUrl(
            redirectUri: config('workos.redirect_uri'),
            clientId: config('workos.client_id'),
            provider: 'authkit',
            organizationId: $organizationId,
        );
    }

    public function logoutUrl(): string
    {
        return $this->userManagement()->getLogoutUrl(
            sessionId: $this->session()?->sessionId,
        );
    }
}
```

**Key decisions**:
- Use `__call()` magic method for SDK service proxying (identical to PR #69)
- Cache service instances in `$instances` array
- Provide convenience methods (`user()`, `session()`, `loginUrl()`)
- Keep SERVICE_MAP as const for immutability

**Implementation steps**:
1. Create WorkOS class with constructor injection
2. Implement `__call()` for SDK proxying with instance caching
3. Add auth convenience methods (`user()`, `session()`, etc.)
4. Add URL generation helpers (`loginUrl()`, `logoutUrl()`)

### WorkOSGuard

**Pattern to follow**: `laravel/sanctum/src/Guard.php`

**Overview**: Custom auth guard that checks WorkOS session state on each request.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class WorkOSGuard implements Guard
{
    protected ?Authenticatable $user = null;

    public function __construct(
        protected ?UserProvider $provider,
        protected SessionManager $session,
        protected Request $request,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $session = $this->session->getValidSession();
        if (! $session) {
            return null;
        }

        $this->user = $this->provider?->retrieveById($session->userId);

        if ($this->user && method_exists($this->user, 'setWorkOSSession')) {
            $this->user->setWorkOSSession($session);
        }

        return $this->user;
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return $this->session->getSession() !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        return $this;
    }
}
```

**Key decisions**:
- Implement full `Guard` interface for Laravel compatibility
- Delegate session checking to SessionManager
- Attach WorkOS session to user model if trait is present
- Cache user to avoid repeated lookups in same request

**Implementation steps**:
1. Create guard class implementing `Illuminate\Contracts\Auth\Guard`
2. Inject UserProvider and SessionManager via constructor
3. Implement `user()` to retrieve from session and user provider
4. Attach WorkOSSession to user model for permission checking

### SessionManager

**Overview**: Manages WorkOS session storage in Laravel's session, handles expiration and refresh.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Session\Session;
use WorkOS\UserManagement;

class SessionManager
{
    private const SESSION_KEY = 'workos_session';

    public function __construct(
        private readonly Session $store,
    ) {}

    public function getSession(): ?WorkOSSession
    {
        $data = $this->store->get(self::SESSION_KEY);
        return $data ? WorkOSSession::fromArray($data) : null;
    }

    public function getValidSession(): ?WorkOSSession
    {
        $session = $this->getSession();
        if (! $session) {
            return null;
        }

        // Check if expired or needs refresh
        if ($session->isExpired()) {
            return $this->attemptRefresh($session);
        }

        if ($session->needsRefresh(config('workos.session.refresh_buffer_minutes', 5))) {
            return $this->attemptRefresh($session) ?? $session;
        }

        return $session;
    }

    public function store(array $authResponse): WorkOSSession
    {
        $session = WorkOSSession::fromAuthResponse($authResponse);
        $this->store->put(self::SESSION_KEY, $session->toArray());
        return $session;
    }

    public function destroy(): void
    {
        $this->store->forget(self::SESSION_KEY);
    }

    public function isImpersonating(): bool
    {
        return $this->getSession()?->impersonator !== null;
    }

    private function attemptRefresh(WorkOSSession $session): ?WorkOSSession
    {
        if (! $session->refreshToken) {
            $this->destroy();
            return null;
        }

        try {
            $response = (new UserManagement())->refreshAuthentication(
                refreshToken: $session->refreshToken,
            );
            return $this->store($response);
        } catch (\Exception $e) {
            $this->destroy();
            return null;
        }
    }
}
```

**Key decisions**:
- Use Laravel's Session contract for storage (framework agnostic)
- Expiration logic uses `expires_at` from WorkOS token
- Only `refresh_buffer_minutes` is locally configurable
- Transparent refresh on access (not background job)

**Implementation steps**:
1. Create SessionManager with Session injection
2. Implement `getSession()` for basic retrieval
3. Implement `getValidSession()` with expiration/refresh logic
4. Implement `store()` for saving auth responses
5. Implement `attemptRefresh()` for token refresh

### WorkOSSession Value Object

**Overview**: Immutable value object representing WorkOS session data.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Carbon\Carbon;

readonly class WorkOSSession
{
    public function __construct(
        public string $userId,
        public string $accessToken,
        public ?string $refreshToken,
        public Carbon $expiresAt,
        public ?string $sessionId,
        public array $roles,
        public array $permissions,
        public ?string $organizationId,
        public ?array $impersonator,
    ) {}

    public static function fromAuthResponse(array $response): self
    {
        $user = $response['user'] ?? [];
        return new self(
            userId: $user['id'],
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'] ?? null,
            expiresAt: Carbon::parse($response['expires_at']),
            sessionId: $response['session_id'] ?? null,
            roles: $user['roles'] ?? [],
            permissions: $user['permissions'] ?? [],
            organizationId: $response['organization_id'] ?? null,
            impersonator: $response['impersonator'] ?? null,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userId: $data['user_id'],
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: Carbon::parse($data['expires_at']),
            sessionId: $data['session_id'] ?? null,
            roles: $data['roles'] ?? [],
            permissions: $data['permissions'] ?? [],
            organizationId: $data['organization_id'] ?? null,
            impersonator: $data['impersonator'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'session_id' => $this->sessionId,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'organization_id' => $this->organizationId,
            'impersonator' => $this->impersonator,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function needsRefresh(int $bufferMinutes): bool
    {
        return $this->expiresAt->subMinutes($bufferMinutes)->isPast();
    }
}
```

**Key decisions**:
- Use PHP 8.1 `readonly` class for immutability
- Parse expiration from WorkOS response (not local config)
- Store roles/permissions from WorkOS for authorization
- Provide helpers for expiration checking

**Implementation steps**:
1. Create readonly class with all session properties
2. Implement `fromAuthResponse()` factory for OAuth callback
3. Implement `fromArray()`/`toArray()` for session storage
4. Add `isExpired()` and `needsRefresh()` helpers

### Config File

**Overview**: Publishable configuration with sensible defaults.

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WorkOS API Credentials
    |--------------------------------------------------------------------------
    */
    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', env('APP_URL').'/auth/callback'),
    'webhook_secret' => env('WORKOS_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Auth Guard Configuration
    |--------------------------------------------------------------------------
    */
    'guard' => 'workos',

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    | Note: Session DURATION is controlled by WorkOS Dashboard, not here.
    | Only the refresh buffer is configurable locally.
    */
    'session' => [
        'refresh_buffer_minutes' => env('WORKOS_SESSION_REFRESH_BUFFER', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'audit_logs' => env('WORKOS_FEATURE_AUDIT_LOGS', false),
        'organizations' => env('WORKOS_FEATURE_ORGANIZATIONS', true),
        'impersonation' => env('WORKOS_FEATURE_IMPERSONATION', true),
        'webhooks' => env('WORKOS_FEATURE_WEBHOOKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks Configuration
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    */
    'user_model' => env('WORKOS_USER_MODEL', 'App\\Models\\User'),
];
```

**Key decisions**:
- All secrets via environment variables
- Explicitly document that session duration is NOT local config
- Feature flags for optional functionality
- Configurable route prefixes

**Implementation steps**:
1. Create config file with all options
2. Document each section with comments
3. Use env() for all sensitive/environment-specific values

### Install Command

**Overview**: Artisan command to scaffold package configuration.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'workos:install';
    protected $description = 'Install WorkOS AuthKit';

    public function handle(): int
    {
        $this->info('Installing WorkOS AuthKit...');

        // Publish config
        $this->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $this->option('force', false),
        ]);
        $this->info('✓ Published config/workos.php');

        // Publish migrations
        $this->callSilently('vendor:publish', [
            '--tag' => 'workos-migrations',
        ]);
        $this->info('✓ Published migrations');

        // Update auth.php
        $this->updateAuthConfig();

        // Display next steps
        $this->newLine();
        $this->info('WorkOS AuthKit installed successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Add to .env:');
        $this->line('     WORKOS_API_KEY=sk_...');
        $this->line('     WORKOS_CLIENT_ID=client_...');
        $this->line('     WORKOS_REDIRECT_URI=' . config('app.url') . '/auth/callback');
        $this->newLine();
        $this->line('  2. Run migrations:');
        $this->line('     php artisan migrate');
        $this->newLine();
        $this->line('  3. Add traits to User model:');
        $this->line('     use HasWorkOSId, HasWorkOSPermissions;');

        return self::SUCCESS;
    }

    protected function updateAuthConfig(): void
    {
        // Add workos guard to auth.php if not present
        // Implementation details...
    }
}
```

**Implementation steps**:
1. Create command class with signature
2. Publish config and migrations
3. Update `config/auth.php` to add workos guard
4. Display helpful next steps

## Data Model

### Schema Changes

```php
// database/migrations/add_workos_id_to_users_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('workos_id');
        });
    }
};
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/WorkOSServiceTest.php` | SDK proxying, helper methods |
| `tests/Unit/SessionManagerTest.php` | Session storage, refresh, expiration |
| `tests/Unit/WorkOSGuardTest.php` | Authentication, user retrieval |
| `tests/Unit/WorkOSSessionTest.php` | Value object creation, serialization |

**Key test cases**:
- WorkOS service proxies to correct SDK classes
- Service instances are cached
- Session stores and retrieves correctly
- Session refresh triggers at buffer threshold
- Session refresh failure destroys session
- Guard returns user when session valid
- Guard returns null when session expired
- Guard attaches session to user model

### Feature Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/InstallCommandTest.php` | Command execution, file creation |
| `tests/Feature/ServiceProviderTest.php` | Registration, bindings, aliases |

**Key scenarios**:
- Install command creates config file
- Service provider registers all bindings
- Facade resolves to correct service
- Helper function works
- Auth guard is registered

### Manual Testing

- [ ] Install package in fresh Laravel app
- [ ] Run `workos:install` command
- [ ] Verify config file created
- [ ] Verify migration published
- [ ] Test `WorkOS::userManagement()` returns SDK instance
- [ ] Test `workos()` helper returns service

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Invalid WorkOS API key | Log error, throw `WorkOSException` with helpful message |
| Session refresh fails | Destroy session, return null (user must re-authenticate) |
| SDK service not found | Throw `InvalidArgumentException` with list of valid services |
| Missing configuration | Throw `ConfigurationException` on first access |

## Validation Commands

```bash
# Install dependencies
composer install

# Type checking (PHPStan)
./vendor/bin/phpstan analyse src --level=8

# Linting (Pint)
./vendor/bin/pint --test

# Unit tests
./vendor/bin/pest --filter=Unit

# Feature tests
./vendor/bin/pest --filter=Feature

# All tests
./vendor/bin/pest

# Coverage report
./vendor/bin/pest --coverage --min=80
```

## Rollout Considerations

- **Feature flag**: None needed for Phase 1
- **Monitoring**: Track SDK initialization errors in logs
- **Alerting**: Alert on repeated auth failures
- **Rollback plan**: Remove package via Composer, restore original auth.php

## Open Items

- [ ] Determine exact WorkOS SDK method signatures for session refresh
- [ ] Verify WorkOS auth response structure for session data extraction

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
